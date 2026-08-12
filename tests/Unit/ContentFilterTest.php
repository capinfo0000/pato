<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Messaging\ContentFilter;
use PHPUnit\Framework\TestCase;

final class ContentFilterTest extends TestCase
{
    private ContentFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new ContentFilter();
    }

    public function test_clean_message_passes(): void
    {
        $this->assertTrue($this->filter->isClean('今日は楽しかったです。またお願いします。'));
        $this->assertSame([], $this->filter->scan('お店の予約しておきますね'));
    }

    public function test_detects_phone_number(): void
    {
        $reasons = $this->filter->scan('連絡先は 090-1234-5678 です');
        $this->assertContains('contact_exchange', $reasons);
    }

    public function test_detects_line_exchange(): void
    {
        $this->assertContains('contact_exchange', $this->filter->scan('LINE交換しませんか'));
    }

    public function test_detects_private_room_solicitation(): void
    {
        $this->assertContains('private_room', $this->filter->scan('この後ホテルでどう？'));
    }

    public function test_detects_cash_direct_deal(): void
    {
        $this->assertContains('cash_direct', $this->filter->scan('アプリ通さず現金手渡しでいこう'));
    }
}
