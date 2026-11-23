<?php

use Modules\Seo\Models\Annotation;

it('seeds the four fleet-wide Google changes with what each suppresses or flags', function () {
    $rows = Annotation::query()->whereNull('site_id')->orderBy('starts_on')->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->map(fn (Annotation $a) => [$a->starts_on->toDateString(), $a->ends_on->toDateString(), $a->suppresses])->all())
        ->toBe([
            ['2025-09-10', '2025-09-14', ['position', 'impressions']],
            ['2026-05-13', '2026-05-13', []],
            ['2026-05-18', '2026-05-18', []],
            ['2026-09-24', '2026-09-24', ['impressions_flag']],
        ])
        ->and($rows->every(fn (Annotation $a) => $a->kind === Annotation::KIND_GOOGLE_CHANGE))->toBeTrue();
});
