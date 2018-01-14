<?php

use seongbae\KeywordRank\KeywordRankServiceProvider;
use seongbae\KeywordRank\Services\KeywordRankFetcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;

class KeywordRankTest extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app)
    {
        return [KeywordRankServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        
    }

    public function setUp()
    {
        parent::setUp();
        Artisan::call('migrate:refresh');
    }

    public function tearDown()
    {
        Artisan::call('migrate:reset');
        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $keywordRankHelperPath = __DIR__.'/Helpers/KeywordRankHelper.php';

        if (file_exists($keywordRankHelperPath))
        {
            require $keywordRankHelperPath;
        }

        $simpleHtmlDom = __DIR__.'/Libs/simple_html_dom.php';

        if (file_exists($simpleHtmlDom))
        {
            require $simpleHtmlDom;
        }
    }

    public function testaddKeyword()
    {
        $testurl = "www.google.com";
        $testname = "Google";
        $testkeyword = "search engine optimization guide";

        $fetcher = new KeywordRankFetcher(Config::get('keywordrank'));
        $website = $fetcher->addWebsite($testurl, $testname, 1);
        $keyword = $fetcher->addKeyword(1, $testkeyword, 1);

        $this->assertDatabaseHas('keywords', [
            'keyword' => 'search engine optimization guide'
        ]);
    }

    public function testaddRanking()
    {
        $testurl = "www.google.com";
        $testname = "Google";
        $testkeyword = "search engine optimization guide";

        $fetcher = new KeywordRankFetcher(Config::get('keywordrank'));
        $website = $fetcher->addWebsite($testurl, $testname, 1);
        $keyword = $fetcher->addKeyword(1, $testkeyword, 1);
        $ranking = $fetcher->addRanking($keyword->id, 1, Carbon::now());

        $this->assertDatabaseHas('rankings', [
            'keyword_id' => $keyword->id,
            'rank' => 1
        ]);
    }

    public function testgetKeywordPosition()
    {
        $testurl = "www.google.com";
        $testkeyword = "Google";

        $fetcher = new KeywordRankFetcher(Config::get('keywordrank'));

        $position = $fetcher->getPosition($testurl,$testkeyword,true);

        $this->assertEquals('1', $position);
    }
}