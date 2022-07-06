<?php

namespace SilverStripe\Template;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Path;
use SilverStripe\Security\Permission;
use SilverStripe\Template\Parser\TemplateParser;
use SilverStripe\Template\View\SSViewer_DataPresenter;
use SilverStripe\Template\View\SSViewer_Scope;
use SilverStripe\View\SSViewer;
use SilverStripe\View\TemplateEngine as ViewTemplateEngine;
use SilverStripe\View\ViewableData;
use Symfony\Component\Filesystem\Filesystem;

class TemplateEngine implements ViewTemplateEngine
{
    private ?TemplateParser $parser = null;

    private ?CacheInterface $partialCacheStore = null;

    /**
     * @internal
     */
    private static $template_cache_flushed = false;

    /**
     * @internal
     */
    private static $cacheblock_cache_flushed = false;

    public function process(string $template, ViewableData $item, array $overlay, array $underlay, ?SSViewer_Scope $inheritedScope = null): string
    {
        $cacheFile = Path::join(
            TEMP_PATH,
            '.ss-template-cache',
            str_replace(['\\','/',':'], '.', Director::makeRelative(realpath($template ?? '')) ?? '')
        );
        $lastEdited = filemtime($template ?? '');

        $filesystem = new Filesystem();
        if (!$filesystem->exists($cacheFile) || filemtime($cacheFile) < $lastEdited) {
            $content = file_get_contents($template ?? '');
            $cachable = $this->parseToCachablePhp(
                $content,
                Director::isDev() && SSViewer::config()->uninherited('source_file_comments'),
                $template
            );

            $filesystem->dumpFile($cacheFile, $cachable);
        }

        if (isset($_GET['showtemplate']) && $_GET['showtemplate'] && Permission::check('ADMIN')) {
            $lines = file($cacheFile ?? '');
            echo "<h2>Template: $cacheFile</h2>";
            echo "<pre>";
            foreach ($lines as $num => $line) {
                echo str_pad($num+1, 5) . htmlentities($line, ENT_COMPAT, 'UTF-8');
            }
            echo "</pre>";
        }

        $cache = $this->getPartialCacheStore();
        $scope = new SSViewer_DataPresenter($item, $overlay, $underlay, $inheritedScope);
        $val = '';

        // Placeholder for values exposed to $cacheFile
        [$cache, $scope, $val];
        include($cacheFile);

        return $val;
    }

    /**
     * Parse a template string into cachable PHP code
     */
    protected function parseToCachablePhp(string $content, bool $includeDebugComments = false, string $templateName = ''): string
    {
        return $this->getParser()->compileString($content, $templateName, $includeDebugComments);
    }

    /**
     * Returns the parser that is set for template generation
     */
    public function getParser(): TemplateParser
    {
        if (!$this->parser) {
            $this->setParser(Injector::inst()->get(TemplateParser::class));
        }
        return $this->parser;
    }

    /**
     * Set the template parser that will be used in template generation
     */
    public function setParser(TemplateParser $parser): self
    {
        $this->parser = $parser;
        return $this;
    }

    /**
     * Triggered early in the request when someone requests a flush.
     */
    public static function flush()
    {
        self::flush_template_cache(true);
        self::flush_cacheblock_cache(true);
    }

    /**
     * Clears all parsed template files in the cache folder.
     * Can only be called once per request (there may be multiple SSViewer instances) unless forced.
     */
    public static function flush_template_cache(bool $force = false): void
    {
        if (!self::$template_cache_flushed || $force) {
            $cacheDir = Path::join(TEMP_PATH, '.ss-template-cache');
            $fileSystem = new Filesystem();
            $fileSystem->remove($cacheDir);
            self::$template_cache_flushed = true;
        }
    }

    /**
     * Clears all partial cache blocks.
     * Can only be called once per request (there may be multiple SSViewer instances) unless forced.
     */
    public static function flush_cacheblock_cache(bool $force = false): void
    {
        if (!self::$cacheblock_cache_flushed || $force) {
            $cache = Injector::inst()->get(CacheInterface::class . '.cacheblock');
            $cache->clear();
            self::$cacheblock_cache_flushed = true;
        }
    }

    /**
     * Set the cache object to use when storing / retrieving partial cache blocks.
     */
    public function setPartialCacheStore(CacheInterface $cache): self
    {
        $this->partialCacheStore = $cache;
        return $this;
    }

    /**
     * Get the cache object to use when storing / retrieving partial cache blocks.
     */
    public function getPartialCacheStore(): CacheInterface
    {
        if ($this->partialCacheStore) {
            return $this->partialCacheStore;
        }
        return Injector::inst()->get(CacheInterface::class . '.cacheblock');
    }
}
