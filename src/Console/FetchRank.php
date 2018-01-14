<?php

namespace seongbae\KeywordRank\Console;

use Illuminate\Console\Command;
use seongbae\KeywordRank\Services\KeywordRankFetcher;
use Illuminate\Support\Facades\Config;

class FetchRank extends Command 
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'fetch:rank {url} {keyword} {--nocache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetches Google keyword position';

    protected $fetcher;
    
    public function __construct()
    {
        parent::__construct();
    }
    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $fetcher = new KeywordRankFetcher(Config::get('keywordrank'));
        $position = $fetcher->getPosition($this->argument('url'), $this->argument('keyword'), $this->option('nocache')); //$fetcher->fetchAll();

        $this->info("Position: ".$position);
    }
}