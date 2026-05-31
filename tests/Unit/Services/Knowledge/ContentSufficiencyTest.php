<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Services\Knowledge\ContentSufficiency;
use PHPUnit\Framework\TestCase;

class ContentSufficiencyTest extends TestCase
{
    private ContentSufficiency $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new ContentSufficiency;
    }

    public function test_empty_text_is_insufficient(): void
    {
        $this->assertFalse($this->gate->isSufficient(''));
    }

    public function test_below_hard_floor_word_count_is_insufficient(): void
    {
        $this->assertFalse($this->gate->isSufficient('two words'));
    }

    public function test_plain_short_text_without_html_is_sufficient(): void
    {
        // No raw HTML supplied → only the hard floor applies (manual text/faq path).
        $this->assertTrue($this->gate->isSufficient('one two three four five six seven'));
    }

    public function test_spa_shell_with_framework_mount_is_insufficient(): void
    {
        // Short text mounted on a known SPA root element (React/base44).
        $clean = 'Tours Book Bhutan Tour Official Website for Book Bhutan Tour visit us today now here';
        $html = '<html><body><div id="root">'.$clean.'</div><p>loading…</p></body></html>';

        $this->assertFalse($this->gate->isSufficient($clean, $html));
    }

    public function test_script_dominated_shell_is_insufficient(): void
    {
        // Short text on a non-framework mount, but the page is mostly inline JS.
        $clean = 'Tours Book Bhutan Tour Official Website for Book Bhutan Tour visit us today now here';
        $html = '<html><body><div id="widget">'.$clean.'</div><script>'.str_repeat('var x=1;', 800).'</script></body></html>';

        $this->assertFalse($this->gate->isSufficient($clean, $html));
    }

    public function test_thin_real_page_in_heavy_template_is_sufficient(): void
    {
        // Regression: a short real page wrapped in a large server-rendered theme
        // (bulky nav/footer markup, only external scripts) must NOT be buried.
        // It has no SPA mount and negligible inline JS — unlike a JS shell.
        $clean = 'Visit our showroom Monday to Friday from nine to five at Main Street Thimphu';
        $chrome = str_repeat('<div class="nav-item"><a href="/page">Menu link label</a></div>', 200);
        $html = '<html><body><header>'.$chrome.'</header><main><p>'.$clean.'</p></main>'
            .'<footer>'.$chrome.'</footer><script src="/theme.js"></script></body></html>';

        $this->assertTrue($this->gate->isSufficient($clean, $html));
    }

    public function test_word_count_uses_whitespace_split(): void
    {
        $this->assertSame(4, $this->gate->wordCount("  one\ntwo   three\tfour  "));
        $this->assertSame(0, $this->gate->wordCount('   '));
    }
}
