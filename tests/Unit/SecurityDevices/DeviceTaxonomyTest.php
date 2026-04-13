<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Config\DeviceTaxonomy;
use PHPUnit\Framework\TestCase;

class DeviceTaxonomyTest extends TestCase
{
    public function test_all_domains_have_categories(): void
    {
        $taxonomy = DeviceTaxonomy::all();

        foreach (DeviceTaxonomy::domains() as $domain) {
            $this->assertArrayHasKey($domain, $taxonomy, "Domain '{$domain}' missing from taxonomy.");
            $this->assertNotEmpty($taxonomy[$domain], "Domain '{$domain}' has no categories.");
        }
    }

    public function test_all_categories_have_subcategories(): void
    {
        foreach (DeviceTaxonomy::all() as $domain => $categories) {
            foreach ($categories as $category => $subcategories) {
                $this->assertNotEmpty(
                    $subcategories,
                    "Category '{$category}' in domain '{$domain}' has no subcategories."
                );
            }
        }
    }

    public function test_is_valid_known_triple(): void
    {
        $this->assertTrue(DeviceTaxonomy::isValid('security', 'cctv', 'dome_camera'));
        $this->assertTrue(DeviceTaxonomy::isValid('it_infrastructure', 'network', 'wireless_ap'));
        $this->assertTrue(DeviceTaxonomy::isValid('tracking', 'personal_tracker', 'wearable_gps'));
    }

    public function test_is_valid_without_subcategory(): void
    {
        $this->assertTrue(DeviceTaxonomy::isValid('security', 'alarm'));
        $this->assertTrue(DeviceTaxonomy::isValid('facilities', 'cold_chain'));
    }

    public function test_is_valid_rejects_unknown_domain(): void
    {
        $this->assertFalse(DeviceTaxonomy::isValid('unknown_domain', 'cctv'));
    }

    public function test_is_valid_rejects_unknown_category(): void
    {
        $this->assertFalse(DeviceTaxonomy::isValid('security', 'nonexistent'));
    }

    public function test_is_valid_rejects_unknown_subcategory(): void
    {
        $this->assertFalse(DeviceTaxonomy::isValid('security', 'cctv', 'nonexistent'));
    }

    public function test_categories_for_domain(): void
    {
        $categories = DeviceTaxonomy::categoriesFor('security');

        $this->assertArrayHasKey('alarm', $categories);
        $this->assertArrayHasKey('cctv', $categories);
        $this->assertArrayHasKey('access_control', $categories);
    }

    public function test_subcategories_for_category(): void
    {
        $subs = DeviceTaxonomy::subcategoriesFor('security', 'cctv');

        $this->assertArrayHasKey('dome_camera', $subs);
        $this->assertArrayHasKey('nvr', $subs);
        $this->assertEquals('Dome Camera', $subs['dome_camera']);
    }

    public function test_subcategories_for_unknown_returns_empty(): void
    {
        $this->assertEmpty(DeviceTaxonomy::subcategoriesFor('security', 'nonexistent'));
    }
}
