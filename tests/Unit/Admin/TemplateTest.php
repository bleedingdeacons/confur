<?php

namespace Tests\Unit\Admin;

use Confur\Admin\Template;

covers(Template::class);

it('renders a template with exactly the variables it is given', function () {
    $html = Template::capture('admin/results', [
        'pageTitle' => 'Result <Report>',
        'generatedAt' => 'now',
        'reportHtml' => '<table id="t"></table>',
    ]);

    expect($html)->toContain('Result &lt;Report&gt;', '<table id="t"></table>', 'Print Report');
});

it('echoes from render()', function () {
    $html = captureOutput(fn () => Template::render('admin/dashboard-widget', [
        'updatesHtml' => '<ul></ul>',
        'lastUpdated' => 'today',
    ]));

    expect($html)->toContain('id="confur-headsup-container"', '<ul></ul>', 'today');
});

it('refuses a name that could leave templates/', function (string $name) {
    Template::render($name);
})->throws(\InvalidArgumentException::class)->with(['../confur', 'admin/../../confur', 'Admin/Status', 'admin/status.php', '']);

it('says which template is missing', function () {
    Template::render('admin/no-such-screen');
})->throws(\RuntimeException::class, 'Template not found: admin/no-such-screen');

it('does not leave output buffered when a template throws', function () {
    $level = ob_get_level();

    try {
        Template::capture('admin/no-such-screen');
    } catch (\RuntimeException) {
    }

    expect(ob_get_level())->toBe($level);
});
