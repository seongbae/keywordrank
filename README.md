Laravel Keyword Rank
=======================

A Laravel package for getting keyword position from Google.  This package works with proxy service from seo-proxies dot com at the moment but the plan is to make it work with any proxy services.

Installation
------------

Add the following line to the `require` section of your Laravel webapp's `composer.json` file:

```javascript
    "require": {
        "seongbae/KeywordRank": "1.*"
    }
```

Run `composer update` to install the package.

This package uses Laravel 5.5 Package Auto-Discovery.
For previous versions of Laravel, you need to update `config/app.php` by adding an entry for the service provider:

```php
'providers' => [
    // ...
    seongbae\KeywordRank\KeywordRankServiceProvider::class,
];
```

Next, publish all package resources:

```bash
    php artisan vendor:publish --provider="seongbae\KeywordRank\KeywordRankServiceProvider"
```

This will add to your project:

    - migration - database tables for storing keywords and positions/rankings
    - configuration - package configurations

Remember to launch migration: 

```bash
    php artisan migrate
```

Next step is to add cron task via Scheduler (`app\Console\Kernel.php`):

```php
    protected function schedule(Schedule $schedule)
    {
    	// ...
        $schedule->command('keywords:fetch')->daily();
    }
```

Usage
------

1) Add website and keyword:

```php	
    $fetcher = new KeywordRankFetcher(Config::get('keywordrank'));
    $website = $fetcher->addWebsite('www.lnidigital', 'LNI Digital Marketing', 1);  // last parameter is user id
    $keyword = $fetcher->addKeyword($website->id, 'Ashburn Digital Marketing', 1); // last parameter is user id
```

2) Get Google keyword position

```php
	$fetcher = new KeywordRankFetcher(Config::get('keywordrank'));
    $position = $fetcher->getPosition('www.lnidigital.com','Ashburn Digital Marketing',true);
```

3) Get Google keyword position from console command

```php
    php artisan fetch:rank www.lnidigital.com 'Ashburn Digital Marketing'
```

By default, above command uses cache if it's run more than once within 24 hours.  If you don't want to use cache, for testing purpose for example, you can add '--nocache' optional argument at the end.

Changelog
---------

1.0
- Create package

Roadmap
-------
- Make the package work with any other proxy services

Credits
-------

This package is created by Seong Bae.  The package utilizes free Google Rank Checker at http://google-rank-checker.squabbel.com/ and simple dom parser at https://github.com/sunra/php-simple-html-dom-parser.