<?php
namespace Tests\Feature;
use Tests\TestCase;

class CustomerNavigationTest extends TestCase
{
    public function test_feed_and_search_share_identical_sidebar_markup_except_current_item(): void
    {
        $markup=[];
        foreach(['/','/search'] as $url){
            $html=$this->get($url)->assertOk()->getContent();
            $dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath=new \DOMXPath($dom);$side=$xpath->query('//aside[contains(@class,"f-sidebar")]');
            $this->assertSame(1,$side->length);
            $this->assertSame(9,$xpath->query('.//button[@data-feed-action]',$side->item(0))->length);
            $this->assertStringNotContainsString('PT Promex Batam City', $dom->saveHTML($side->item(0)));
            $this->assertStringNotContainsString('c-nav-label', $dom->saveHTML($side->item(0)));
            foreach($xpath->query('.//*[@aria-current]',$side->item(0)) as $node)$node->removeAttribute('aria-current');
            $markup[]=$dom->saveHTML($side->item(0));
        }
        $this->assertSame($markup[0],$markup[1]);
    }

    public function test_customer_deep_links_render_correct_initial_navigation_without_new_routes(): void
    {
        foreach(['following','saved'] as $view)$this->get('/?view='.$view)->assertOk()->assertSee('data-feed-action="'.$view.'" aria-label=',false);
        $this->get('/?panel=messages')->assertOk()->assertSee('data-feed-action="messages"',false);
        $this->get('/search')->assertOk()->assertSee('id="search-input"',false)->assertSee('id="sidebar-search"',false)->assertDontSee('aria-label="Tema tampilan"',false)->assertSee('data-feed-action="settings"',false)->assertSee('id="customer-more"',false)->assertSee('Masuk / Daftar')->assertSee(route('account.settings'))->assertSee(route('help.index'))->assertDontSee('Belum tersedia');
    }

    public function test_search_has_no_redundant_header_and_filter_actions_stay_inside_closed_disclosure(): void
    {
        $html=$this->get('/search')->assertOk()->assertDontSee('aria-label="Kembali ke feed"',false)->assertDontSee('data-open-search-drawer',false)->getContent();
        $dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new \DOMXPath($dom);
        $panel=$xpath->query('//details[@id="search-filters"]')->item(0);
        $this->assertFalse($panel->hasAttribute('open'));
        $this->assertSame(0,$xpath->query('//header[contains(@class,"s-header")]')->length);
        $this->assertSame(1,$xpath->query('.//button[@id="apply-search-filters"]',$panel)->length);
        $this->assertSame(1,$xpath->query('.//button[@type="reset"]',$panel)->length);
        $this->assertSame(1,$xpath->query('//input[@id="search-input"]')->length);
        $this->assertSame(1,$xpath->query('//dialog[@id="customer-search-drawer"]')->length);
    }
}
