<?php

namespace craftpulse\cortex\tools\system;

use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `search_skills` tool — in-memory full-text search across the bundled
 * skills corpus.
 *
 * Cortex ships ~27,000 lines of authored Craft expertise across 8
 * skills (each with a `SKILL.md` router plus N reference documents)
 * and 5 Claude Code agents. The MCP prompt + resource registry exposes
 * each addressable unit by URI, but the LLM has no way to ask "where
 * is this concept covered?" without listing every resource and
 * fetching them in turn.
 *
 * This tool indexes the corpus at request time (cheap — bytes are on
 * disk and read once) and returns ranked matches for a free-text
 * query. Each result carries the resource URI so the LLM can follow
 * up with `resources/read` to load the full content. Scoring is a
 * simple word-occurrence sum normalised by document length — useful
 * for "find me the section on element authorisation" queries, not for
 * vector-similarity-style semantic search. Vectorised search may
 * layer on top in a future release; this tool stays as the
 * cheap-and-fast keyword path.
 *
 * Modes:
 *   - `search` (default): rank documents against the query.
 *   - `topics`: enumerate the corpus without scoring — `[{kind, uri,
 *     skill, name?}]` rows the LLM can use to discover what's
 *     available.
 *
 * Filter `kind` to `skill` (router only), `reference` (deep dives),
 * or `agent` (Claude Code agent definitions). Omit for all three.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[Title('Search Bundled Skills')]
#[IsReadOnly]
#[IsIdempotent]
class SearchSkills extends AbstractTool
{
    // Constants
    // =========================================================================

    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 50;

    public const KIND_SKILL = 'skill';
    public const KIND_REFERENCE = 'reference';
    public const KIND_AGENT = 'agent';

    /**
     * Bytes of context emitted around the first query match in each
     * result's snippet. Total snippet length is roughly
     * `2 * SNIPPET_HALF_WIDTH` plus the matched run.
     */
    private const SNIPPET_HALF_WIDTH = 120;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'search_skills';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Full-text search across the bundled craft-skills corpus — 8 skills, ' .
            'their reference deep-dives, and 5 Claude Code agents. `mode: "search"` ' .
            '(default) returns ranked matches with a snippet and the resource URI for ' .
            'follow-up reads; `mode: "topics"` enumerates the corpus without scoring. ' .
            'Filter `kind` to `skill` / `reference` / `agent` to narrow.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'mode' => Schema::string()
                ->enum(['search', 'topics'])
                ->description('Optional. `search` (default) ranks documents; `topics` enumerates without scoring.'),
            'query' => Schema::string()
                ->description('Free-text query. Required for `search` mode; ignored for `topics`.')
                ->examples(['element save lifecycle', 'matrix block field', 'multi-site propagation']),
            'kind' => Schema::string()
                ->enum([self::KIND_SKILL, self::KIND_REFERENCE, self::KIND_AGENT])
                ->description('Optional filter. `skill` = router only; `reference` = deep dives; `agent` = Claude Code agents.'),
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $mode = $this->_mode($arguments) ?? 'search';

        return match ($mode) {
            'search' => $this->_search($arguments),
            'topics' => $this->_topics($arguments),
            default => throw new ToolException("Unknown mode: '{$mode}'. Allowed: search, topics."),
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _search(array $arguments): array
    {
        $query = $arguments['query'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            throw new ToolException('`query` is required for mode=search and must be a non-empty string.');
        }

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $kindFilter = $this->_kindFilter($arguments);
        $tokens = $this->_tokenise($query);
        if ($tokens === []) {
            throw new ToolException('`query` must contain at least one alphanumeric token.');
        }

        $index = $this->_buildIndex($kindFilter);
        $scored = [];
        foreach ($index as $entry) {
            $score = $this->_score($tokens, $entry['content']);
            if ($score['total'] <= 0) {
                continue;
            }
            $scored[] = [
                'kind' => $entry['kind'],
                'uri' => $entry['uri'],
                'skill' => $entry['skill'],
                'name' => $entry['name'],
                'source' => $entry['source'],
                'score' => round($score['total'], 4),
                'matches' => $score['hits'],
                'snippet' => $this->_snippet($entry['content'], $score['firstPos']),
            ];
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $page = array_slice($scored, 0, $limit);

        return [
            'mode' => 'search',
            'query' => $query,
            'kind' => $kindFilter,
            'count' => count($page),
            'totalMatches' => count($scored),
            'limit' => $limit,
            'results' => $page,
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _topics(array $arguments): array
    {
        $kindFilter = $this->_kindFilter($arguments);
        $index = $this->_buildIndex($kindFilter);

        $rows = array_map(
            static fn(array $entry): array => [
                'kind' => $entry['kind'],
                'uri' => $entry['uri'],
                'skill' => $entry['skill'],
                'name' => $entry['name'],
                'source' => $entry['source'],
                'length' => mb_strlen($entry['content']),
            ],
            $index,
        );

        return [
            'mode' => 'topics',
            'kind' => $kindFilter,
            'count' => count($rows),
            'topics' => $rows,
        ];
    }

    /**
     * Build the in-memory document index. Delegates to
     * `Skills::getMergedCorpus()` so bundled + element-stored skills
     * surface in a single union with element-stored winning on handle
     * collision (locked decision 2 of Gate 8.6). Each row carries a
     * `source: 'bundled' | 'element'` field that propagates to the
     * `_search` / `_topics` envelopes.
     *
     * The service memoizes the result; reads after the first one are
     * O(1) on the in-memory `MemoizableArray`.
     *
     * @return list<array{kind:string,uri:string,skill:string,name:string|null,content:string,source:string}>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _buildIndex(?string $kindFilter): array
    {
        /** @var list<array{kind:string,uri:string,skill:string,name:string|null,content:string,source:string}> $rows */
        $rows = Cortex::getInstance()->skills->getMergedCorpus($kindFilter);
        return $rows;
    }

    /**
     * Score a document against the tokenised query. Returns the total
     * score, a per-token hit count, and the position of the earliest
     * match (used to anchor the snippet). Score is occurrence count
     * weighted by token length — longer tokens score higher because
     * they're rarer and therefore more discriminating.
     *
     * @param list<string> $tokens
     * @return array{total:float,hits:array<string,int>,firstPos:int}
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _score(array $tokens, string $content): array
    {
        $haystack = mb_strtolower($content);
        $contentLength = max(1, mb_strlen($haystack));
        $hits = [];
        $totalScore = 0.0;
        $firstPos = -1;

        foreach ($tokens as $token) {
            $count = substr_count($haystack, $token);
            if ($count === 0) {
                continue;
            }
            $hits[$token] = $count;
            // Length-weighted occurrence count, normalised by content
            // length so a long doc with 5 hits doesn't beat a short doc
            // with 5 hits.
            $totalScore += ($count * mb_strlen($token)) / log($contentLength + 1);

            $pos = mb_strpos($haystack, $token);
            if ($pos !== false && ($firstPos === -1 || $pos < $firstPos)) {
                $firstPos = $pos;
            }
        }

        return [
            'total' => $totalScore,
            'hits' => $hits,
            'firstPos' => max(0, $firstPos),
        ];
    }

    /**
     * Extract a snippet centred on the matched position. Trims to word
     * boundaries on both sides so the snippet doesn't cut mid-word.
     * Returns the original-case content (not the lowercased
     * scoring-haystack copy) so renderers preserve markdown formatting.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _snippet(string $content, int $position): string
    {
        $start = max(0, $position - self::SNIPPET_HALF_WIDTH);
        $length = self::SNIPPET_HALF_WIDTH * 2;

        $window = mb_substr($content, $start, $length);

        // Trim partial-word edges.
        if ($start > 0) {
            $firstSpace = mb_strpos($window, ' ');
            if ($firstSpace !== false && $firstSpace < 30) {
                $window = mb_substr($window, $firstSpace + 1);
            }
            $window = '…' . $window;
        }

        if (mb_strlen($content) > $start + $length) {
            $lastSpace = mb_strrpos($window, ' ');
            if ($lastSpace !== false && $lastSpace > mb_strlen($window) - 30) {
                $window = mb_substr($window, 0, $lastSpace);
            }
            $window .= '…';
        }

        // Collapse runs of whitespace so the snippet stays compact.
        $window = (string) preg_replace('/\s+/', ' ', $window);

        return trim($window);
    }

    /**
     * Tokenise the query into lowercased alphanumeric runs of length
     * >= 2. Drops stopword-shaped one-character tokens that would match
     * almost everything and inflate scores.
     *
     * @return list<string>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _tokenise(string $query): array
    {
        $matches = [];
        $count = preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($query), $matches);
        if (!is_int($count) || $count === 0) {
            return [];
        }
        return array_values(array_unique($matches[0]));
    }

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _kindFilter(array $arguments): ?string
    {
        $kind = $arguments['kind'] ?? null;
        if (!is_string($kind) || $kind === '') {
            return null;
        }
        if (!in_array($kind, [self::KIND_SKILL, self::KIND_REFERENCE, self::KIND_AGENT], true)) {
            return null;
        }
        return $kind;
    }
}
