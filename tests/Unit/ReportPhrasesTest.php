<?php

use Modules\ClientReports\Support\ReportPhrases;

describe('first name for the greeting', function () {
    it('takes the first name of a person\'s name', function (string $name, string $first) {
        expect(ReportPhrases::firstName($name))->toBe($first);
    })->with([
        ['Jane Example', 'Jane'],
        ['Sam', 'Sam'],
        ['  Robin   Sample  ', 'Robin'],
        ["D'Arcy O'Example", "D'Arcy"],
        ['Mary-Jane Example', 'Mary-Jane'],
        ['Jo Ann Example', 'Jo'],
        ['Élodie Exemple', 'Élodie'],
    ]);

    it('gives no name for anything that is not plainly one person\'s', function (?string $name) {
        expect(ReportPhrases::firstName($name))->toBeNull();
    })->with([
        'empty' => '',
        'null' => null,
        'an organisation word' => 'Example Institute',
        'a church' => 'St Example Church',
        'an acronym' => 'EXCO',
        'an acronym with a word' => 'XY Example',
        'digits' => 'EXC4P',
        'an initial' => 'R. Example',
        'a bare initial' => 'R Example',
        'a title' => 'Dr. Jane Example',
        'a note in brackets' => 'Jane (Example Strategy)',
        'an ampersand' => 'Example & Sons',
        'more than three words' => 'Example Girl Interior Shop',
        'run together' => 'ExampleSupply',
        'all lower case' => 'jane example',
        'an email address' => 'jane@example.com',
        'a domain' => 'example.com',
    ]);
});
