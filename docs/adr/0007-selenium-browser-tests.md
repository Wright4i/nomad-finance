# Browser tests use Selenium

Browser tests drive the built UI in Chrome through Selenium (`php-webdriver` and a ChromeDriver binary matched to the installed Chrome). `composer test:browser` starts its own PHP server and a throwaway database. `composer test` stays the unit suite and does not need Chrome.
