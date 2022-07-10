<?php

namespace SilverStripe\Template;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Flushable;
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

class TemplateEngine implements ViewTemplateEngine, Flushable
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
        $cacheFile = Path::join(static::getCacheDir(), $this->getCacheKey($template));
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
        self::flush_template_cache();
        self::flush_cacheblock_cache();
    }

    /**
     * Clears all parsed template files in the cache folder.
     */
    public static function flush_template_cache(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->remove(static::getCacheDir());
    }

    /**
     * Clears all partial cache blocks.
     */
    public static function flush_cacheblock_cache(): void
    {
        $cache = Injector::inst()->get(CacheInterface::class . '.cacheblock');
        $cache->clear();
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

    /**
     * Parse a template string into cachable PHP code
     */
    protected function parseToCachablePhp(string $content, bool $includeDebugComments = false, string $templateName = ''): string
    {
        return $this->getParser()->compileString($content, $templateName, $includeDebugComments);
    }

    /**
     * Get the cache key for a given template file
     */
    protected function getCacheKey(string $template): string
    {
        return str_replace(['\\','/',':'], '.', Director::makeRelative(realpath($template ?? '')) ?? '') . '-php';
    }

    /**
     * Get the path to the directory where template cache is stored.
     */
    protected static function getCacheDir(): string
    {
        return Path::join(TEMP_PATH, '.ss-template-cache');
    }
}
