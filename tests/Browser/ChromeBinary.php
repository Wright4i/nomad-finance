<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use RuntimeException;

final class ChromeBinary
{
    public static function chrome(): string
    {
        $candidates = [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        throw new RuntimeException('Install Google Chrome so the browser tests can start.');
    }

    public static function driver(): string
    {
        $version = self::version(self::chrome());
        $platform = self::platform();
        $dir = dirname(__DIR__, 2) . '/var/chromedriver';
        $binary = $dir . DIRECTORY_SEPARATOR . 'chromedriver';
        $stamp = $dir . '/VERSION';
        $mark = $version . ' ' . $platform;
        if (is_file($binary) && is_file($stamp) && trim((string) file_get_contents($stamp)) === $mark) {
            return $binary;
        }

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir);
        }

        $url = sprintf(
            'https://storage.googleapis.com/chrome-for-testing-public/%s/%s/chromedriver-%s.zip',
            $version,
            $platform,
            $platform,
        );
        $zip = $dir . '/chromedriver.zip';
        $handle = fopen($zip, 'w');
        if ($handle === false) {
            throw new RuntimeException('Could not write ' . $zip);
        }
        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            throw new RuntimeException('Could not start a download of ' . $url);
        }
        curl_setopt($curl, CURLOPT_FILE, $handle);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_FAILONERROR, true);
        $ok = curl_exec($curl);
        $status = curl_error($curl);
        unset($curl);
        fclose($handle);
        if ($ok !== true) {
            throw new RuntimeException('Could not download ChromeDriver ' . $version . ' (' . $status . ').');
        }

        $extracted = $dir . '/extracted';
        if (is_dir($extracted)) {
            self::removeTree($extracted);
        }
        mkdir($extracted, 0775, true);
        $unzip = proc_open(
            ['unzip', '-o', '-q', $zip, '-d', $extracted],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($unzip)) {
            throw new RuntimeException('Could not unzip ChromeDriver.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($unzip);
        if ($code !== 0) {
            throw new RuntimeException('Could not unzip ChromeDriver. ' . $output);
        }

        $found = glob($extracted . '/*/chromedriver') ?: [];
        if ($found === []) {
            throw new RuntimeException('The ChromeDriver archive did not contain a chromedriver binary.');
        }
        if (is_file($binary)) {
            unlink($binary);
        }
        if (!rename($found[0], $binary)) {
            throw new RuntimeException('Could not install ChromeDriver into ' . $binary);
        }
        chmod($binary, 0755);
        exec('xattr -d com.apple.quarantine ' . escapeshellarg($binary) . ' 2>/dev/null');
        file_put_contents($stamp, $mark);
        unlink($zip);
        self::removeTree($extracted);

        return $binary;
    }

    public static function version(string $chrome): string
    {
        $output = shell_exec(escapeshellarg($chrome) . ' --version 2>/dev/null');
        if (!is_string($output) || !preg_match('/(\d+\.\d+\.\d+\.\d+)/', $output, $match)) {
            throw new RuntimeException('Could not read the Chrome version from ' . $chrome);
        }

        return $match[1];
    }

    private static function platform(): string
    {
        $arch = php_uname('m');
        $arm = $arch === 'arm64' || $arch === 'aarch64';
        if (PHP_OS_FAMILY === 'Darwin') {
            return $arm ? 'mac-arm64' : 'mac-x64';
        }
        if (PHP_OS_FAMILY === 'Linux') {
            return $arm ? 'linux-arm64' : 'linux64';
        }

        throw new RuntimeException('Browser tests run on macOS or Linux.');
    }

    private static function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
