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

    public function test_spa_shell_with_low_text_ratio_is_insufficient(): void
    {
        // ~16 words of body text, but the page is dominated by a JS bundle.
        $clean = 'Tours Book Bhutan Tour Official Website for Book Bhutan Tour visit us today now here';
        $html = '<html><body><div id="root">'.$clean.'</div><script>'.str_repeat('var x=1;', 800).'</script></body></html>';

        $this->assertFalse($this->gate->isSufficient($clean, $html));
    }

    public function test_real_short_page_with_high_text_ratio_is_sufficient(): void
    {
        $clean = 'Visit our showroom Monday to Friday from nine to five at Main Street Thimphu';
        $html = '<html><body><p>'.$clean.'</p></body></html>';

        $this->assertTrue($this->gate->isSufficient($clean, $html));
    }

    public function test_word_count_uses_whitespace_split(): void
    {
        $this->assertSame(4, $this->gate->wordCount("  one\ntwo   three\tfour  "));
        $this->assertSame(0, $this->gate->wordCount('   '));
    }
}
