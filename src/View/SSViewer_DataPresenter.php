<?php

namespace SilverStripe\Template\View;

use InvalidArgumentException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\View\SSViewer;
use SilverStripe\View\ViewableData;

/**
 * This extends SSViewer_Scope to mix in data on top of what the item provides. This can be "global"
 * data that is scope-independant (like BaseURL), or type-specific data that is layered on top cross-cut like
 * (like $FirstLast etc).
 *
 * It's separate from SSViewer_Scope to keep that fairly complex code as clean as possible.
 */
class SSViewer_DataPresenter extends SSViewer_Scope
{
    /**
     * List of global iterator providers
     *
     * @internal
     * @var TemplateIteratorProvider[]|null
     */
    private static $iteratorProperties = null;

    /**
     * Overlay variables. Take precedence over anything from the current scope
     *
     * @var array|null
     */
    protected $overlay;

    /**
     * Underlay variables. Concede precedence to overlay variables or anything from the current scope
     *
     * @var array
     */
    protected $underlay;

    /**
     * @var object $item
     * @var array $overlay
     * @var array $underlay
     * @var SSViewer_Scope $inheritedScope
     */
    public function __construct(
        $item,
        array $overlay = null,
        array $underlay = null,
        SSViewer_Scope $inheritedScope = null
    ) {
        parent::__construct($item, $inheritedScope);

        $this->overlay = $overlay ?: [];
        $this->underlay = $underlay ?: [];

        $this->cacheIteratorProperties();
    }

    /**
     * Build cache of global iterator properties
     */
    protected function cacheIteratorProperties()
    {
        if (self::$iteratorProperties !== null) {
            return;
        }

        self::$iteratorProperties = SSViewer::getPropertiesFromProvider(
            TemplateIteratorProvider::class,
            'get_template_iterator_variables',
            true // Call non-statically
        );
    }

    /**
     * Look up injected value - it may be part of an "overlay" (arguments passed to <% include %>),
     * set on the current item, part of an "underlay" ($Layout or $Content), or an iterator/global property
     *
     * @param string $property Name of property
     * @param array $params
     * @param bool $cast If true, an object is always returned even if not an object.
     * @return array|null
     */
    public function getInjectedValue($property, array $params, $cast = true)
    {
        // Get source for this value
        $source = $this->getValueSource($property);
        if (!$source) {
            return null;
        }

        // Look up the value - either from a callable, or from a directly provided value
        $res = [];
        if (isset($source['callable'])) {
            $res['value'] = $source['callable'](...$params);
        } elseif (isset($source['value'])) {
            $res['value'] = $source['value'];
        } else {
            throw new InvalidArgumentException(
                "Injected property $property doesn't have a value or callable value source provided"
            );
        }

        // If we want to provide a casted object, look up what type object to use
        if ($cast) {
            $res['obj'] = $this->castValue($res['value'], $source);
        }

        return $res;
    }

    /**
     * Store the current overlay (as it doesn't directly apply to the new scope
     * that's being pushed). We want to store the overlay against the next item
     * "up" in the stack (hence upIndex), rather than the current item, because
     * SSViewer_Scope::obj() has already been called and pushed the new item to
     * the stack by this point
     *
     * @return SSViewer_Scope
     */
    public function pushScope()
    {
        $scope = parent::pushScope();
        $upIndex = $this->getUpIndex();

        if ($upIndex !== null) {
            $itemStack = $this->getItemStack();
            $itemStack[$upIndex][SSViewer_Scope::ITEM_OVERLAY] = $this->overlay;

            $this->setItemStack($itemStack);
            $this->overlay = [];
        }

        return $scope;
    }

    /**
     * Now that we're going to jump up an item in the item stack, we need to
     * restore the overlay that was previously stored against the next item "up"
     * in the stack from the current one
     *
     * @return SSViewer_Scope
     */
    public function popScope()
    {
        $upIndex = $this->getUpIndex();

        if ($upIndex !== null) {
            $itemStack = $this->getItemStack();
            $this->overlay = $itemStack[$this->getUpIndex()][SSViewer_Scope::ITEM_OVERLAY];
        }

        return parent::popScope();
    }

    /**
     * $Up and $Top need to restore the overlay from the parent and top-level
     * scope respectively.
     *
     * @param string $name
     * @param array $arguments
     * @param bool $cache
     * @param string $cacheName
     * @return $this
     */
    public function obj($name, $arguments = [], $cache = false, $cacheName = null)
    {
        $overlayIndex = false;

        switch ($name) {
            case 'Up':
                $upIndex = $this->getUpIndex();
                if ($upIndex === null) {
                    throw new \LogicException('Up called when we\'re already at the top of the scope');
                }

                $overlayIndex = $upIndex; // Parent scope
                break;
            case 'Top':
                $overlayIndex = 0; // Top-level scope
                break;
        }

        if ($overlayIndex !== false) {
            $itemStack = $this->getItemStack();
            if (!$this->overlay && isset($itemStack[$overlayIndex][SSViewer_Scope::ITEM_OVERLAY])) {
                $this->overlay = $itemStack[$overlayIndex][SSViewer_Scope::ITEM_OVERLAY];
            }
        }

        parent::obj($name, $arguments, $cache, $cacheName);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getObj($name, $arguments = [], $cache = false, $cacheName = null)
    {
        $result = $this->getInjectedValue($name, (array)$arguments);
        if ($result) {
            return $result['obj'];
        }
        return parent::getObj($name, $arguments, $cache, $cacheName);
    }

    /**
     * {@inheritdoc}
     */
    public function __call($name, $arguments)
    {
        // Extract the method name and parameters
        $property = $arguments[0];  // The name of the public function being called

        // The public function parameters in an array
        $params = (isset($arguments[1])) ? (array)$arguments[1] : [];

        $val = $this->getInjectedValue($property, $params);
        if ($val) {
            $obj = $val['obj'];
            if ($name === 'hasValue') {
                $result = ($obj instanceof ViewableData) ? $obj->exists() : (bool)$obj;
            } else {
                $result = $obj->forTemplate(); // XML_val
            }

            $this->resetLocalScope();
            return $result;
        }

        return parent::__call($name, $arguments);
    }

    /**
     * Evaluate a template override
     *
     * @param string $property Name of override requested
     * @param array $overrides List of overrides available
     * @return null|array Null if not provided, or array with 'value' or 'callable' key
     */
    protected function processTemplateOverride($property, $overrides)
    {
        if (!isset($overrides[$property])) {
            return null;
        }

        // Detect override type
        $override = $overrides[$property];

        // Late-evaluate this value
        if (!is_string($override) && is_callable($override)) {
            $override = $override();

            // Late override may yet return null
            if (!isset($override)) {
                return null;
            }
        }

        return [ 'value' => $override ];
    }

    /**
     * Determine source to use for getInjectedValue
     *
     * @param string $property
     * @return array|null
     */
    protected function getValueSource($property)
    {
        // Check for a presenter-specific override
        $overlay = $this->processTemplateOverride($property, $this->overlay);
        if (isset($overlay)) {
            return $overlay;
        }

        // Check if the method to-be-called exists on the target object - if so, don't check any further
        // injection locations
        $on = $this->itemIterator ? $this->itemIterator->current() : $this->item;
        if (isset($on->$property) || method_exists($on, $property ?? '')) {
            return null;
        }

        // Check for a presenter-specific override
        $underlay = $this->processTemplateOverride($property, $this->underlay);
        if (isset($underlay)) {
            return $underlay;
        }

        // Then for iterator-specific overrides
        if (array_key_exists($property, self::$iteratorProperties)) {
            $source = self::$iteratorProperties[$property];
            /** @var TemplateIteratorProvider $implementor */
            $implementor = $source['implementor'];
            if ($this->itemIterator) {
                // Set the current iterator position and total (the object instance is the first item in
                // the callable array)
                $implementor->iteratorProperties(
                    $this->itemIterator->key(),
                    $this->itemIteratorTotal
                );
            } else {
                // If we don't actually have an iterator at the moment, act like a list of length 1
                $implementor->iteratorProperties(0, 1);
            }
            return $source;
        }

        // And finally for global overrides
        $globalProperties = SSViewer::getGlobalProperties();
        if (array_key_exists($property, $globalProperties)) {
            return $globalProperties[$property];  //get the method call
        }

        // No value
        return null;
    }

    /**
     * Ensure the value is cast safely
     *
     * @param mixed $value
     * @param array $source
     * @return DBField
     */
    protected function castValue($value, $source)
    {
        // Already cast
        if (is_object($value)) {
            return $value;
        }

        // Get provided or default cast
        $casting = empty($source['casting'])
            ? ViewableData::config()->uninherited('default_cast')
            : $source['casting'];

        return DBField::create_field($casting, $value);
    }
}
