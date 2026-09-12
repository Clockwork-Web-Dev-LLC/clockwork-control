<?php

use App\Support\ScheduledCommandName;

test('normalize strips the php binary and artisan prefix', function () {
    expect(ScheduledCommandName::normalize("'/opt/homebrew/bin/php' 'artisan' clockwork:refresh-cisa-kev"))
        ->toBe('clockwork:refresh-cisa-kev');
});

test('normalize keeps trailing flags intact', function () {
    expect(ScheduledCommandName::normalize("'/usr/bin/php' 'artisan' clockwork:run-performance-scans --strategy=mobile --weekly-rotation"))
        ->toBe('clockwork:run-performance-scans --strategy=mobile --weekly-rotation');
});
