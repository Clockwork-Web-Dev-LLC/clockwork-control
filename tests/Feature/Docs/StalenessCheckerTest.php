<?php

use App\Services\Docs\DocPage;
use App\Services\Docs\StalenessChecker;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Coverage for App\Services\Docs\StalenessChecker
|--------------------------------------------------------------------------
|
| Exercises it against a real, throwaway git repo (not this app's own repo)
| so commit dates/timezones can be controlled exactly. The main thing under
| test is the 2026-09-03 fix: `latestCommitFor()` used to compare a UTC
| unix timestamp against the doc's `updated:` frontmatter date (always a
| human's LOCAL calendar day), so a commit made in the evening US-Eastern —
| already past midnight UTC — misreported as landing "the next day" and
| flagged same-session docs as stale. It now asks git for the commit's own
| local calendar date instead of a UTC timestamp.
*/
function makeTempGitRepo(): string
{
    $dir = sys_get_temp_dir().'/staleness-checker-test-'.uniqid();
    mkdir($dir);

    $run = function (array $args) use ($dir) {
        $process = new Process($args, $dir);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git command failed: '.$process->getErrorOutput());
        }
    };

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test']);

    return $dir;
}

function commitFileAt(string $repo, string $relativePath, string $isoDateWithOffset): void
{
    $abs = $repo.'/'.$relativePath;
    @mkdir(dirname($abs), recursive: true);
    file_put_contents($abs, "content at {$isoDateWithOffset}");

    $env = [
        'GIT_AUTHOR_DATE' => $isoDateWithOffset,
        'GIT_COMMITTER_DATE' => $isoDateWithOffset,
    ];

    foreach ([['git', 'add', $relativePath], ['git', 'commit', '-q', '-m', 'test commit']] as $args) {
        $process = new Process($args, $repo, $env);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git command failed: '.$process->getErrorOutput());
        }
    }
}

function pageTracking(array $tracks, string $updated): DocPage
{
    return new DocPage(
        slug: 'test/page',
        title: 'Test',
        section: 'Features',
        order: 1,
        updated: Carbon::parse($updated),
        author: 'Test',
        tags: [],
        tracks: $tracks,
        htmlBody: '',
        excerpt: '',
    );
}

afterEach(function () {
    if (isset($this->tempRepo) && is_dir($this->tempRepo)) {
        (new Process(['rm', '-rf', $this->tempRepo]))->run();
    }
});

it('does not flag a doc as stale when the tracked commit landed the same evening US-Eastern, even though that instant is past midnight UTC', function () {
    $this->tempRepo = makeTempGitRepo();

    // 10:14pm EDT on 2026-09-02 is 02:14am UTC on 2026-09-03 — the exact
    // shape of the false positive this fix addresses (see api-endpoints
    // catalog note / commit 09f9c0e in the real repo for a live example).
    commitFileAt($this->tempRepo, 'src/Foo.php', '2026-09-02T22:14:28-04:00');

    $page = pageTracking(['src/Foo.php'], '2026-09-02');

    $result = (new StalenessChecker($this->tempRepo))->evaluate($page);

    expect($result['stale'])->toBeFalse();
    expect($result['days_behind'])->toBe(0);
});

it('still flags a doc as stale when the tracked commit genuinely landed a later local calendar day', function () {
    $this->tempRepo = makeTempGitRepo();

    commitFileAt($this->tempRepo, 'src/Foo.php', '2026-09-05T09:00:00-04:00');

    $page = pageTracking(['src/Foo.php'], '2026-09-02');

    $result = (new StalenessChecker($this->tempRepo))->evaluate($page);

    expect($result['stale'])->toBeTrue();
    expect($result['days_behind'])->toBe(3);
});

it('does not flag same-day intra-day commits as stale regardless of time of day', function () {
    $this->tempRepo = makeTempGitRepo();

    commitFileAt($this->tempRepo, 'src/Foo.php', '2026-09-02T23:59:59-04:00');

    $page = pageTracking(['src/Foo.php'], '2026-09-02');

    $result = (new StalenessChecker($this->tempRepo))->evaluate($page);

    expect($result['stale'])->toBeFalse();
});

it('flags a commit that genuinely lands on the next LOCAL calendar day, one second after midnight', function () {
    $this->tempRepo = makeTempGitRepo();

    commitFileAt($this->tempRepo, 'src/Foo.php', '2026-09-03T00:00:01-04:00');

    $page = pageTracking(['src/Foo.php'], '2026-09-02');

    $result = (new StalenessChecker($this->tempRepo))->evaluate($page);

    expect($result['stale'])->toBeTrue();
    expect($result['days_behind'])->toBe(1);
});

it('is not stale when there are no tracks', function () {
    $this->tempRepo = makeTempGitRepo();

    $page = pageTracking([], '2026-09-02');

    $result = (new StalenessChecker($this->tempRepo))->evaluate($page);

    expect($result['stale'])->toBeFalse();
    expect($result['tracked_paths'])->toBe([]);
});

it('treats a missing `updated:` date as stale-with-unknown days_behind', function () {
    $this->tempRepo = makeTempGitRepo();

    commitFileAt($this->tempRepo, 'src/Foo.php', '2026-09-02T12:00:00-04:00');

    $page = new DocPage(
        slug: 'test/page',
        title: 'Test',
        section: 'Features',
        order: 1,
        updated: null,
        author: 'Test',
        tags: [],
        tracks: ['src/Foo.php'],
        htmlBody: '',
        excerpt: '',
    );

    $result = (new StalenessChecker($this->tempRepo))->evaluate($page);

    expect($result['stale'])->toBeTrue();
    expect($result['days_behind'])->toBeNull();
});
