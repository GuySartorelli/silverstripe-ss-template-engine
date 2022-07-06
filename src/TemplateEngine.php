<?php

namespace SilverStripe\Template;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Template\Parser\TemplateParser;
use SilverStripe\View\TemplateEngine as ViewTemplateEngine;

class TemplateEngine implements ViewTemplateEngine
{
    private ?TemplateParser $parser = null;

    public function renderString(string $content, bool $includeDebugComments = false, string $templateName = ''): string
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
    public function setParser(TemplateParser $parser)
    {
        $this->parser = $parser;
    }
}
