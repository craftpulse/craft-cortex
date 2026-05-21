<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('routes');
});

it('returns the four route categories plus site count', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys([
        'configFileRoutes', 'projectConfigRoutes',
        'sectionRoutes', 'categoryGroupRoutes', 'siteCount',
    ]);
    expect($result['configFileRoutes'])->toBeArray();
    expect($result['projectConfigRoutes'])->toBeArray();
    expect($result['sectionRoutes'])->toBeArray();
    expect($result['categoryGroupRoutes'])->toBeArray();
});

it('section routes are normalised with sectionHandle, siteHandle, uriFormat, template', function() {
    $result = $this->tool->execute([]);

    foreach ($result['sectionRoutes'] as $route) {
        expect($route)->toHaveKeys([
            'sectionHandle', 'sectionType', 'siteId',
            'siteHandle', 'uriFormat', 'template',
        ]);
    }
});
