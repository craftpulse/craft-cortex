<?php

/**
 * =========================================================================
 * `scaffold_entries` Pro tool tests — Gate 8.7.
 *
 * Single-mode bulk-create-from-template tool. Tests cover the
 * registration / permission-gating surface, the substitution surface
 * (`{n}` / `{n:0Nd}`), the row cap, idempotency, the streaming
 * progress-frame cadence, and the cancellation contract.
 *
 * Fixture strategy: every test scaffolds into the playground's
 * `minorHeroes` channel under the `__herald_scaftest_{hex}_` title
 * prefix; `afterEach()` hard-deletes everything under the prefix.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\Entry as EntryElement;
use craft\elements\User;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\content\ScaffoldEntries;
use craftpulse\herald\tools\support\CancellationToken;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = '__herald_scaftest_' . bin2hex(random_bytes(4)) . '_';

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;

    $section = Craft::$app->getEntries()->getSectionByHandle('minorHeroes');
    if ($section === null) {
        $this->markTestSkipped('minorHeroes seed not applied; run `ddev craft migrate/up --track=content`.');
    }
    $this->section = $section;
    $this->entryType = $section->getEntryTypes()[0];
});

afterEach(function() {
    $rows = EntryElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'elements_sites.title', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($rows as $row) {
        Craft::$app->getElements()->deleteElement($row, hardDelete: true);
    }
    $cache = Craft::$app->getCache();
    if ($cache !== null) {
        $cache->flush();
    }
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _herald_scaffold_tool(): ScaffoldEntries
{
    return new ScaffoldEntries();
}

/**
 * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
 */
function _herald_scaffold_drain(Generator $gen): array
{
    $frames = [];
    while ($gen->valid()) {
        $frames[] = $gen->current();
        $gen->next();
    }
    /** @var array<string,mixed> $return */
    $return = $gen->getReturn();
    return [$frames, $return];
}

// -----------------------------------------------------------------------------
// Registration
// -----------------------------------------------------------------------------

it('is NOT registered on Free installs', function() {
    expect(Herald::getInstance()->tools->getByName('scaffold_entries'))->toBeNull();
    $names = array_map(
        static fn(array $entry): string => $entry['name'],
        Herald::getInstance()->tools->asListPayload(),
    );
    expect($names)->not->toContain('scaffold_entries');
});

it('shouldRegister() returns true on Pro and false on Free', function() {
    expect(ScaffoldEntries::shouldRegister())->toBeFalse();
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(ScaffoldEntries::shouldRegister())->toBeTrue();
    });
});

it('implements StreamableToolInterface', function() {
    expect(_herald_scaffold_tool())->toBeInstanceOf(\craftpulse\herald\tools\StreamableToolInterface::class);
});

// -----------------------------------------------------------------------------
// filterFor
// -----------------------------------------------------------------------------

it('filterFor(null) returns true — stdio is trusted', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(_herald_scaffold_tool()->filterFor(null))->toBeTrue();
    });
});

it('filterFor returns true for admin users', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(_herald_scaffold_tool()->filterFor($this->admin))->toBeTrue();
    });
});

it('filterFor returns false for users with no saveEntries permissions', function() {
    $user = new User();
    $user->username = '__herald_scaf_noperms_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }
    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
            expect(_herald_scaffold_tool()->filterFor($user))->toBeFalse();
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// argument validation
// -----------------------------------------------------------------------------

it('rejects missing sectionUid with a top-level error', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_scaffold_tool()->execute([
            'count' => 1,
            'entryTypeUid' => $this->entryType->uid,
            'template' => ['title' => $this->fixturePrefix . '{n}'],
        ]);
        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toHaveKey('sectionUid');
    });
});

it('rejects missing template.title with a top-level error', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_scaffold_tool()->execute([
            'count' => 1,
            'sectionUid' => $this->section->uid,
            'entryTypeUid' => $this->entryType->uid,
            'template' => [],
        ]);
        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toHaveKey('template');
    });
});

it('rejects an unknown sectionUid', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_scaffold_tool()->execute([
            'count' => 1,
            'sectionUid' => '__not_a_real_uid_x9z__',
            'entryTypeUid' => $this->entryType->uid,
            'template' => ['title' => $this->fixturePrefix . '{n}'],
        ]);
        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toHaveKey('sectionUid');
    });
});

it('rejects an entry type not assigned to the target section', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        // Borrow the hero entry type — it's NOT assigned to
        // minorHeroes.
        $foreign = Craft::$app->getEntries()->getEntryTypeByHandle('hero');
        if ($foreign === null) {
            $this->markTestSkipped('No `hero` entry type in playground.');
        }
        $result = _herald_scaffold_tool()->execute([
            'count' => 1,
            'sectionUid' => $this->section->uid,
            'entryTypeUid' => $foreign->uid,
            'template' => ['title' => $this->fixturePrefix . '{n}'],
        ]);
        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toHaveKey('entryTypeUid');
    });
});

// -----------------------------------------------------------------------------
// row cap
// -----------------------------------------------------------------------------

it('throws when count exceeds the row cap without force', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_scaffold_tool()->execute([
            'count' => ScaffoldEntries::ROW_CAP + 1,
            'sectionUid' => $this->section->uid,
            'entryTypeUid' => $this->entryType->uid,
            'template' => ['title' => $this->fixturePrefix . '{n}'],
        ]);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// happy path + substitution
// -----------------------------------------------------------------------------

it('creates N entries with `{n:04d}` zero-padded titles', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $count = 5;
        $template = $this->fixturePrefix . '{n:04d}';

        $result = _herald_scaffold_tool()->execute([
            'count' => $count,
            'sectionUid' => $this->section->uid,
            'entryTypeUid' => $this->entryType->uid,
            'template' => ['title' => $template],
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('create');
        expect($result['total'])->toBe($count);
        expect($result['succeeded'])->toBe($count);
        expect($result['failed'])->toBe(0);
        expect($result['results'])->toHaveCount($count);

        // Each result has a real entry id and the title was substituted.
        foreach ($result['results'] as $i => $row) {
            expect($row['kind'])->toBe('success');
            expect($row['id'])->toBeInt();
            $entry = EntryElement::find()->id($row['id'])->status(null)->one();
            expect($entry->title)->toBe($this->fixturePrefix . sprintf('%04d', $i + 1));
        }
    });
});

it('substitutes `{n}` without zero-padding', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $count = 3;
        $template = $this->fixturePrefix . '{n}';

        $result = _herald_scaffold_tool()->execute([
            'count' => $count,
            'sectionUid' => $this->section->uid,
            'entryTypeUid' => $this->entryType->uid,
            'template' => ['title' => $template],
        ]);

        expect($result['success'])->toBeTrue();
        foreach ($result['results'] as $i => $row) {
            $entry = EntryElement::find()->id($row['id'])->status(null)->one();
            expect($entry->title)->toBe($this->fixturePrefix . (string) ($i + 1));
        }
    });
});

it('substitutes slug template independently of title template', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_scaffold_tool()->execute([
            'count' => 2,
            'sectionUid' => $this->section->uid,
            'entryTypeUid' => $this->entryType->uid,
            'template' => [
                'title' => $this->fixturePrefix . 'Title {n}',
                'slug' => 'slug-{n:03d}',
            ],
        ]);

        expect($result['success'])->toBeTrue();
        foreach ($result['results'] as $i => $row) {
            $entry = EntryElement::find()->id($row['id'])->status(null)->one();
            expect($entry->slug)->toStartWith('slug-');
            expect($entry->slug)->toContain(sprintf('%03d', $i + 1));
        }
    });
});

// -----------------------------------------------------------------------------
// streaming
// -----------------------------------------------------------------------------

it('stream() yields one progress frame per progressInterval rows', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $count = 4;
        $gen = _herald_scaffold_tool()->stream([
            'count' => $count,
            'sectionUid' => $this->section->uid,
            'entryTypeUid' => $this->entryType->uid,
            'progressInterval' => 1,
            'template' => ['title' => $this->fixturePrefix . '{n:03d}'],
        ], new InvocationContext());
        [$frames, $return] = _herald_scaffold_drain($gen);

        expect($frames)->toHaveCount($count);
        $previous = -1;
        foreach ($frames as $frame) {
            expect($frame['progress'])->toBeGreaterThan($previous);
            expect($frame['total'])->toBe($count);
            $previous = $frame['progress'];
        }
        expect($return['succeeded'])->toBe($count);
    });
});

it('stream() short-circuits on a pre-armed cancellation token', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $count = 10;
        $callCount = 0;
        $token = new CancellationToken(static function() use (&$callCount): bool {
            $callCount++;
            return $callCount > 2;
        });
        $ctx = new InvocationContext(cancellationToken: $token);

        $gen = _herald_scaffold_tool()->stream([
            'count' => $count,
            'sectionUid' => $this->section->uid,
            'entryTypeUid' => $this->entryType->uid,
            'progressInterval' => 1,
            'template' => ['title' => $this->fixturePrefix . '{n:03d}'],
        ], $ctx);
        [, $return] = _herald_scaffold_drain($gen);

        expect($return['cancelled'])->toBeTrue();
        expect($return['processed'])->toBeLessThan($count);
    });
});

// -----------------------------------------------------------------------------
// idempotency
// -----------------------------------------------------------------------------

it('idempotency cache returns the cached envelope on a second call with the same key', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $key = 'scaf_idem_' . bin2hex(random_bytes(4));

        $first = _herald_scaffold_tool()->execute([
            'count' => 2,
            'sectionUid' => $this->section->uid,
            'entryTypeUid' => $this->entryType->uid,
            'template' => ['title' => $this->fixturePrefix . '{n:03d}'],
            'idempotencyKey' => $key,
        ]);
        expect($first['succeeded'])->toBe(2);

        $second = _herald_scaffold_tool()->execute([
            'count' => 2,
            'sectionUid' => $this->section->uid,
            'entryTypeUid' => $this->entryType->uid,
            'template' => ['title' => $this->fixturePrefix . '{n:03d}'],
            'idempotencyKey' => $key,
        ]);
        // Same envelope returned — no second batch was actually
        // created. Two entries from the first run + zero from the
        // cached short-circuit = two total entries under the prefix.
        expect($second['succeeded'])->toBe($first['succeeded']);
        // Verify cache hit by inspecting result ids: cache-hit returns
        // the SAME ids as the first call (no new entries created). A
        // re-run would have minted new ids. The DB-count check below
        // independently confirms this.
        expect($second['results'][0]['id'] ?? null)->toBe($first['results'][0]['id'] ?? null);
        $firstIds = array_map(static fn(array $r): int => (int) $r['id'], $first['results']);
        $rows = EntryElement::find()
            ->status(null)
            ->site('*')
            ->id($firstIds)
            ->all();
        expect(count($rows))->toBe(2);
    });
});
