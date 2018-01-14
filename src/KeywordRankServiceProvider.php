<?php 
namespace seongbae\KeywordRank;

use Illuminate\Support\ServiceProvider;
use seongbae\KeywordRank\Services\KeywordRankFetcher;
use seongbae\KeywordRank\Console\FetchRank;

class KeywordRankServiceProvider extends ServiceProvider 
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Facade stuff
        \App::bind('keywordrank', function()
        {
            return new KeywordRankFetcher;
        });

        // publish config file
        $configPath = __DIR__ . '/../config/keywordrank.php';
        $this->mergeConfigFrom($configPath, 'keywordrank');
        $this->publishes([
             $configPath => config_path('keywordrank.php')
        ], 'config');

        // Register fetch:rank command
        $this->initCommand('fetchrank', FetchRank::class);
    }

    private function initCommand($name, $class)
    {
        $this->app->singleton("command.{$name}", function($app) use ($class) {
            return new $class($app);
        });

        $this->commands("command.{$name}");
    }

    /**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
        $this->registerHelpers();

        $this->loadMigrations();
	}

    /**
     * Register helpers file
     */
    public function registerHelpers()
    {
        $keywordRankHelperPath = __DIR__.'/Helpers/KeywordRankHelper.php';

        if (file_exists($keywordRankHelperPath))
        {
            require_once $keywordRankHelperPath;
        }

        $simpleHtmlDom = __DIR__.'/Libs/simple_html_dom.php';

        if (file_exists($simpleHtmlDom))
        {
            require_once $simpleHtmlDom;
        }

    }

    protected function loadMigrations()
    {
        $migrationPath = __DIR__.'/../database/migrations';

        $this->publishes([
            $migrationPath => base_path('database/migrations'),
        ], 'migrations');

        $this->loadMigrationsFrom($migrationPath);
    }

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		// return [
  //           'keywordrank'
  //       ];
	}
}