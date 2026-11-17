<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php/project_links.php';

final class ProjectLinksTest extends TestCase
{
    public function testAcceptsAnOptionalHttpOrHttpsProjectLink(): void
    {
        self::assertTrue(fuzzy_project_link_is_valid('https://example.com/demo'));
        self::assertTrue(fuzzy_project_link_is_valid('http://example.com/demo'));
    }

    public function testRejectsMalformedAndNonHttpProjectLinks(): void
    {
        self::assertFalse(fuzzy_project_link_is_valid(''));
        self::assertFalse(fuzzy_project_link_is_valid('not a url'));
        self::assertFalse(fuzzy_project_link_is_valid('javascript:alert(1)'));
        self::assertFalse(fuzzy_project_link_is_valid('ftp://example.com/demo'));
        self::assertFalse(fuzzy_project_link_is_valid('https://example.com/' . str_repeat('a', 1000)));
    }

    public function testExistingProviderLinksRemainHostRestricted(): void
    {
        self::assertTrue(fuzzy_project_link_is_valid('https://drive.google.com/project', ['drive.google.com', 'docs.google.com']));
        self::assertFalse(fuzzy_project_link_is_valid('https://example.com/project', ['drive.google.com', 'docs.google.com']));
    }
}
