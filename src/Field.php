<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\redactor;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Volume;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\HtmlPurifier;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\Section;
use craft\redactor\assets\field\FieldAsset;
use craft\redactor\assets\redactor\RedactorAsset;
use craft\redactor\events\ModifyPurifierConfigEvent;
use craft\redactor\events\ModifyRedactorConfigEvent;
use craft\redactor\events\RegisterLinkOptionsEvent;
use craft\redactor\events\RegisterPluginPathsEvent;
use craft\validators\HandleValidator;
use HTMLPurifier_Config;
use yii\base\Event;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\db\Schema;

/**
 * Redactor field type
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Field extends \craft\base\Field
{
    // Constants
    // =========================================================================

    /**
     * @event RegisterPluginPathsEvent The event that is triggered when registering paths that contain Redactor plugins.
     */
    const EVENT_REGISTER_PLUGIN_PATHS = 'registerPluginPaths';

    /**
     * @event RegisterLinkOptionsEvent The event that is triggered when registering the link options for the field.
     */
    const EVENT_REGISTER_LINK_OPTIONS = 'registerLinkOptions';

    /**
     * @event ModifyPurifierConfigEvent The event that is triggered when creating HTML Purifier config
     *
     * Plugins can get notified when HTML Purifier config is being constructed.
     *
     * ```php
     * use craft\redactor\events\ModifyPurifierConfigEvent;
     * use craft\redactor\Field;
     * use HTMLPurifier_AttrDef_Text;
     * use yii\base\Event;
     *
     * Event::on(Field::class, Field::EVENT_MODIFY_PURIFIER_CONFIG, function(ModifyPurifierConfigEvent $e) {
     *      // Allow the use of the Redactor Variables plugin
     *      $e->config->getHTMLDefinition(true)->addAttribute('span', 'data-redactor-type', new HTMLPurifier_AttrDef_Text());
     * });
     * ```
     */
    const EVENT_MODIFY_PURIFIER_CONFIG = 'modifyPurifierConfig';

    /**
     * @event ModifyRedactorConfigEvent The event that is triggered when loading redactor config.
     *
     * Plugins can get notified when redactor config is loaded
     *
     * ```php
     * use craft\redactor\events\ModifyRedactorConfigEvent;
     * use craft\redactor\Field;
     * use yii\base\Event;
     *
     * Event::on(Field::class, Field::EVENT_DEFINE_REDACTOR_CONFIG, function(ModifyRedactorConfigEvent $e) {
     *      // Never allow the bold button for reasons.
     *     $e->config['buttonsHide'] = empty($e->config['buttonsHide']) ? ['bold'] : array_merge($e->config['buttonsHide'], ['bold']);
     * });
     * ```
     */
    const EVENT_DEFINE_REDACTOR_CONFIG = 'defineRedactorConfig';

    // Static
    // =========================================================================

    /**
     * @var array List of the Redactor plugins that have already been registered for this request
     */
    private static $_registeredPlugins = [];

    /**
     * @var array|null List of the paths that may contain Redactor plugins
     */
    private static $_pluginPaths;

    // Properties
    // =========================================================================

    /**
     * @var string The UI mode of the field.
     * @since 2.7.0
     */
    public $uiMode = 'enlarged';

    /**
     * @var string|null The Redactor config file to use
     */
    public $redactorConfig;

    /**
     * @var string|null The HTML Purifier config file to use
     */
    public $purifierConfig;

    /**
     * @var bool Whether the HTML should be cleaned up on save
     * @deprecated in 2.4
     */
    public $cleanupHtml = true;

    /**
     * @var bool Whether disallowed inline styles should be removed on save
     */
    public $removeInlineStyles = true;

    /**
     * @var bool Whether empty tags should be removed on save
     */
    public $removeEmptyTags = true;

    /**
     * @var bool Whether non-breaking spaces should be replaced by regular spaces on save
     */
    public $removeNbsp = true;

    /**
     * @var bool Whether the HTML should be purified on save
     */
    public $purifyHtml = true;

    /**
     * @var string The type of database column the field should have in the content table
     */
    public $columnType = Schema::TYPE_TEXT;

    /**
     * @var string|array|null The volumes that should be available for Image selection.
     */
    public $availableVolumes = '*';

    /**
     * @var string|array|null The transforms available when selecting an image
     */
    public $availableTransforms = '*';

    /**
     * @var bool Whether to show input sources for volumes the user doesn’t have permission to view.
     * @since 2.6.0
     */
    public $showUnpermittedVolumes = false;

    /**
     * @var bool Whether to show files the user doesn’t have permission to view, per the
     * “View files uploaded by other users” permission.
     * @since 2.6.0
     */
    public $showUnpermittedFiles = false;

    /**
     * @var bool Whether "view source" button should only be displayed to admins.
     * @since 2.7.0
     */
    public $showHtmlButtonForNonAdmins = false;

    /**
     * @var string Config selection mode ('choose' or 'manual')
     * @since 2.7.0
     */
    public $configSelectionMode = 'choose';

    /**
     * @var string Manual config to use
     * @since 2.7.0
     */
    public $manualConfig = '';

    /**
     * @var string The default transform to use.
     */
    public $defaultTransform = '';

    /**
     * @inheritdoc
     */
    public function __construct(array $config = [])
    {
        // Default showHtmlButtonForNonAdmins to true for existing Assets fields
        if (isset($config['id']) && !isset($config['showHtmlButtonForNonAdmins'])) {
            $config['showHtmlButtonForNonAdmins'] = true;
        }

        // normalize a mix/match of ids and uids to a list of uids.
        if (isset($config['availableVolumes']) && is_array($config['availableVolumes'])) {
            $ids = [];
            $uids = [];

            foreach ($config['availableVolumes'] as $availableVolume) {
                if (is_int($availableVolume)) {
                    $ids[] = $availableVolume;
                } else {
                    $uids[] = $availableVolume;
                }
            }

            if (!empty($ids)) {
                $uids = array_merge($uids, Db::uidsByIds('{{%volumes}}', $ids));
            }

            $config['availableVolumes'] = $uids;
        }

        // normalize a mix/match of ids and uids to a list of uids.
        if (isset($config['availableTransforms']) && is_array($config['availableTransforms'])) {
            $ids = [];
            $uids = [];

            foreach ($config['availableTransforms'] as $availableTransform) {
                if (is_int($availableTransform)) {
                    $ids[] = $availableTransform;
                } else {
                    $uids[] = $availableTransform;
                }
            }

            if (!empty($ids)) {
                $uids = array_merge($uids, Db::uidsByIds('{{%assettransforms}}', $ids));
            }

            $config['availableTransforms'] = $uids;
        }

        // configFile => redactorConfig
        if (isset($config['configFile'])) {
            $config['redactorConfig'] = ArrayHelper::remove($config, 'configFile');
        }

        // Default showUnpermittedVolumes to true for existing Redactor fields
        if (isset($config['id']) && !isset($config['showUnpermittedVolumes'])) {
            $config['showUnpermittedVolumes'] = true;
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->showUnpermittedVolumes = (bool)$this->showUnpermittedVolumes;
        $this->showUnpermittedFiles = (bool)$this->showUnpermittedFiles;
        parent::init();
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('redactor', 'Redactor');
    }

    /**
     * Registers a Redactor plugin's JS & CSS files.
     *
     * @param string $plugin
     * @return void
     * @throws InvalidConfigException if the plugin can't be found
     */
    public static function registerRedactorPlugin(string $plugin)
    {
        if (isset(self::$_registeredPlugins[$plugin])) {
            return;
        }

        $paths = self::redactorPluginPaths();
        $view = Craft::$app->getView();

        foreach ($paths as $registeredPath) {
            foreach (["{$registeredPath}/{$plugin}", $registeredPath] as $path) {
                if (file_exists("{$path}/{$plugin}.js")) {
                    $baseUrl = Craft::$app->getAssetManager()->getPublishedUrl($path, true);
                    $view->registerJsFile("{$baseUrl}/{$plugin}.js", [
                        'depends' => RedactorAsset::class
                    ]);
                    // CSS file too?
                    if (file_exists("{$path}/{$plugin}.css")) {
                        $view->registerCssFile("{$baseUrl}/{$plugin}.css");
                    }
                    // Don't do this twice
                    self::$_registeredPlugins[$plugin] = true;
                    return;
                }
            }
        }

        throw new InvalidConfigException('Redactor plugin not found: ' . $plugin);
    }

    /**
     * Returns the registered Redactor plugin paths.
     *
     * @return string[]
     */
    public static function redactorPluginPaths(): array
    {
        if (self::$_pluginPaths !== null) {
            return self::$_pluginPaths;
        }

        $event = new RegisterPluginPathsEvent([
            'paths' => [
                Craft::getAlias('@config/redactor/plugins'),
                __DIR__ . '/assets/redactor-plugins/dist',
            ]
        ]);
        Event::trigger(self::class, self::EVENT_REGISTER_PLUGIN_PATHS, $event);

        return self::$_pluginPaths = $event->paths;
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        $volumeOptions = [];
        /** @var $volume Volume */
        foreach (Craft::$app->getVolumes()->getPublicVolumes() as $volume) {
            if ($volume->hasUrls) {
                $volumeOptions[] = [
                    'label' => Html::encode($volume->name),
                    'value' => $volume->uid
                ];
            }
        }

        $transformOptions = [];
        foreach (Craft::$app->getAssetTransforms()->getAllTransforms() as $transform) {
            $transformOptions[] = [
                'label' => Html::encode($transform->name),
                'value' => $transform->uid
            ];
        }

        return Craft::$app->getView()->renderTemplate('redactor/_field_settings', [
            'field' => $this,
            'redactorConfigOptions' => $this->_getCustomConfigOptions('redactor'),
            'purifierConfigOptions' => $this->_getCustomConfigOptions('htmlpurifier'),
            'volumeOptions' => $volumeOptions,
            'transformOptions' => $transformOptions,
            'defaultTransformOptions' => array_merge([
                [
                    'label' => Craft::t('redactor', 'No transform'),
                    'value' => null
                ]
            ], $transformOptions),
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['manualConfig'], 'trim'];
        $rules[] = [
            ['manualConfig'],
            function() {
                if (!Json::isJsonObject($this->manualConfig)) {
                    $this->addError('manualConfig', Craft::t('redactor', 'This must be a valid JSON object.'));
                    return;
                }
                try {
                    Json::decode($this->manualConfig);
                } catch (InvalidArgumentException $e) {
                    $this->addError('manualConfig', Craft::t('redactor', 'This must be a valid JSON object.'));
                }
            }
        ];
        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getContentColumnType(): string
    {
        return $this->columnType;
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue($value, ElementInterface $element = null)
    {
        if ($value instanceof FieldData) {
            return $value;
        }

        if (!$value) {
            return null;
        }

        // Prevent everyone from having to use the |raw filter when outputting RTE content
        /** @var Element|null $element */
        return new FieldData($value, $element->siteId ?? null);
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml($value, ElementInterface $element = null): string
    {
        /** @var FieldData|null $value */
        /** @var Element $element */

        // register the asset/redactor bundles
        $view = Craft::$app->getView();
        $view->registerAssetBundle(FieldAsset::class);

        // figure out which language we ended up with
        /** @var RedactorAsset $bundle */
        $bundle = $view->getAssetManager()->getBundle(RedactorAsset::class);
        $redactorLang = $bundle::$redactorLanguage ?? 'en';

        // register plugins
        $redactorConfig = $this->_getRedactorConfig();

        if (isset($redactorConfig['plugins'])) {
            foreach ($redactorConfig['plugins'] as $plugin) {
                static::registerRedactorPlugin($plugin);
            }
        }

        if (!$this->showHtmlButtonForNonAdmins && !Craft::$app->getUser()->getIsAdmin()) {
            $redactorConfig['buttonsHide'] = array_merge($redactorConfig['buttonsHide'] ?? [], ['html']);
        }

        $id = Html::id($this->handle);
        $site = ($element ? $element->getSite() : Craft::$app->getSites()->getCurrentSite());

        $defaultTransform = '';

        if (!empty($this->defaultTransform) && $transform = Craft::$app->getAssetTransforms()->getTransformByUid($this->defaultTransform)) {
            $defaultTransform = $transform->handle;
        }

        $settings = [
            'id' => $view->namespaceInputId($id),
            'linkOptions' => $this->_getLinkOptions($element),
            'volumes' => $this->_getVolumeKeys(),
            'transforms' => $this->_getTransforms(),
            'defaultTransform' => $defaultTransform,
            'elementSiteId' => $site->id,
            'redactorConfig' => $redactorConfig,
            'redactorLang' => $redactorLang,
            'showAllUploaders' => $this->showUnpermittedFiles,
        ];

        if ($this->translationMethod != self::TRANSLATION_METHOD_NONE) {
            // Explicitly set the text direction
            $locale = Craft::$app->getI18n()->getLocaleById($site->language);
            $settings['direction'] = $locale->getOrientation();
        }

        RedactorAsset::registerTranslations($view);
        $view->registerJs('new Craft.RedactorInput(' . Json::encode($settings) . ');');

        if ($value instanceof FieldData) {
            $value = $value->getRawContent();
        }

        if ($value !== null) {
            // Parse reference tags
            $value = $this->_parseRefs($value, $element);

            // Swap any <!--pagebreak-->'s with <hr>'s
            $value = str_replace('<!--pagebreak-->', Html::tag('hr', '', [
                'class' => 'redactor_pagebreak',
                'style' => ['display' => 'none'],
                'unselectable' => 'on',
                'contenteditable' => 'false',
            ]), $value);
        }

        $textarea = Html::textarea($this->handle, $value, [
            'id' => $id,
            'style' => ['display' => 'none'],
        ]);

        return Html::tag('div', $textarea, [
            'class' => [
                'redactor',
                $this->uiMode,
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getStaticHtml($value, ElementInterface $element): string
    {
        /** @var FieldData|null $value */
        return Html::tag('div', $value ?: '&nbsp;', [
            'class' => array_filter([
                'text',
                $this->uiMode === 'enlarged' ? 'readable' : null,
            ]),
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function searchKeywords($value, ElementInterface $element): string
    {
        $keywords = parent::searchKeywords($value, $element);

        if (Craft::$app->getDb()->getIsMysql()) {
            $keywords = StringHelper::encodeMb4($keywords);
        }

        return $keywords;
    }

    /**
     * @inheritdoc
     */
    public function isValueEmpty($value, ElementInterface $element): bool
    {
        if ($value === null) {
            return true;
        }

        /** @var FieldData $value */
        return parent::isValueEmpty($value->getRawContent(), $element);
    }

    /**
     * @inheritdoc
     */
    public function serializeValue($value, ElementInterface $element = null)
    {
        /** @var FieldData|null $value */
        if (!$value) {
            return null;
        }

        // Get the raw value
        $value = $value->getRawContent();

        // Temporary fix (hopefully) for a Redactor bug where some HTML will get submitted when the field is blank,
        // if any text was typed into the field, and then deleted
        if ($value === '<p><br></p>') {
            $value = '';
        }

        if ($value) {
            // Swap any pagebreak <hr>'s with <!--pagebreak-->'s
            $value = preg_replace('/<hr class="redactor_pagebreak".*?>/', '<!--pagebreak-->', $value);

            if ($this->purifyHtml) {
                // Parse reference tags so HTMLPurifier doesn't encode the curly braces
                $value = $this->_parseRefs($value, $element);

                // Sanitize & tokenize any SVGs
                $svgTokens = [];
                $svgContent = [];
                $value = preg_replace_callback('/<svg\b.*>.*<\/svg>/Uis', function(array $match) use (&$svgTokens, &$svgContent): string {
                    $svgContent[] = Html::sanitizeSvg($match[0]);
                    return $svgTokens[] = 'svg:' . StringHelper::randomString(10);
                }, $value);

                $value = HtmlPurifier::process($value, $this->_getPurifierConfig());

                // Put the sanitized SVGs back
                $value = str_replace($svgTokens, $svgContent, $value);
            }

            if ($this->removeInlineStyles) {
                // Remove <font> tags
                $value = preg_replace('/<(?:\/)?font\b[^>]*>/', '', $value);

                // Remove disallowed inline styles
                $allowedStyles = $this->_allowedStyles();
                $value = preg_replace_callback(
                    '/(<(?:h1|h2|h3|h4|h5|h6|p|div|blockquote|pre|strong|em|b|i|u|a|span|img)\b[^>]*)\s+style="([^"]*)"/',
                    function(array $matches) use ($allowedStyles) {
                        // Only allow certain styles through
                        $allowed = [];
                        $styles = explode(';', $matches[2]);
                        foreach ($styles as $style) {
                            list($name, $value) = array_map('trim', array_pad(explode(':', $style, 2), 2, ''));
                            if (isset($allowedStyles[$name])) {
                                $allowed[] = "{$name}: {$value}";
                            }
                        }
                        return $matches[1] . (!empty($allowed) ? ' style="' . implode('; ', $allowed) . '"' : '');
                    },
                    $value
                );
            }

            if ($this->removeEmptyTags) {
                // Remove empty tags
                $value = preg_replace('/<(h1|h2|h3|h4|h5|h6|p|div|blockquote|pre|strong|em|a|b|i|u|span)\s*><\/\1>/', '', $value);
            }

            if ($this->removeNbsp) {
                // Replace non-breaking spaces with regular spaces
                $value = preg_replace('/(&nbsp;|&#160;|\x{00A0})/u', ' ', $value);
                $value = preg_replace('/  +/', ' ', $value);
            }
        }

        // Find any element URLs and swap them with ref tags
        $value = preg_replace_callback(
            '/(href=|src=)([\'"])([^\'"\?#]*)(\?[^\'"\?#]+)?(#[^\'"\?#]+)?(?:#|%23)([\w\\\\]+)\:(\d+)(?:@(\d+))?(\:(?:transform\:)?' . HandleValidator::$handlePattern . ')?\2/',
            function($matches) {
                list(, $attr, $q, $url, $query, $hash, $elementType, $ref, $siteId, $transform) = array_pad($matches, 10, null);

                // Create the ref tag, and make sure :url is in there
                $ref = $elementType . ':' . $ref . ($siteId ? "@$siteId" : '') . ($transform ?: ':url');

                if ($query || $hash) {
                    // Make sure that the query/hash isn't actually part of the parsed URL
                    // - someone's Entry URL Format could include "?slug={slug}" or "#{slug}", etc.
                    // - assets could include ?mtime=X&focal=none, etc.
                    $parsed = Craft::$app->getElements()->parseRefs("{{$ref}}");
                    if ($query) {
                        // Decode any HTML entities, e.g. &amp;
                        $query = Html::decode($query);
                        if (mb_strpos($parsed, $query) !== false) {
                            $url .= $query;
                            $query = '';
                        }
                    }
                    if ($hash && mb_strpos($parsed, $hash) !== false) {
                        $url .= $hash;
                        $hash = '';
                    }
                }

                return $attr . $q . '{' . $ref . '||' . $url . '}' . $query . $hash . $q;
            },
            $value);

        if (Craft::$app->getDb()->getIsMysql()) {
            // Encode any 4-byte UTF-8 characters.
            $value = StringHelper::encodeMb4($value);
        }

        return $value;
    }

    // Private Methods
    // =========================================================================

    /**
     * Parse ref tags in URLs, while preserving the original tag values in the URL fragments
     * (e.g. `href="{entry:id:url}"` => `href="[entry-url]#entry:id:url"`)
     *
     * @param string $value
     * @param ElementInterface|null $element
     * @return string
     */
    private function _parseRefs(string $value, ElementInterface $element = null): string
    {
        if (!StringHelper::contains($value, '{')) {
            return $value;
        }

        return preg_replace_callback('/(href=|src=)([\'"])(\{([\w\\\\]+\:\d+(?:@\d+)?\:(?:transform\:)?' . HandleValidator::$handlePattern . ')(?:\|\|[^\}]+)?\})(?:\?([^\'"#]*))?(#[^\'"#]+)?\2/', function($matches) use ($element) {
            /** @var Element|null $element */
            list ($fullMatch, $attr, $q, $refTag, $ref, $query, $fragment) = array_pad($matches, 7, null);
            $parsed = Craft::$app->getElements()->parseRefs($refTag, $element->siteId ?? null);
            // If the ref tag couldn't be parsed, leave it alone
            if ($parsed === $refTag) {
                return $fullMatch;
            }
            if ($query) {
                // Decode any HTML entities, e.g. &amp;
                $query = Html::decode($query);
                if (mb_strpos($parsed, $query) !== false) {
                    $parsed = UrlHelper::urlWithParams($parsed, $query);
                }
            }
            return $attr . $q . $parsed . ($fragment ?? '') . '#' . $ref . $q;
        }, $value);
    }

    /**
     * Returns the link options available to the field.
     * Each link option is represented by an array with the following keys:
     * - `optionTitle` (required) – the user-facing option title that appears in the Link dropdown menu
     * - `elementType` (required) – the element type class that the option should be linking to
     * - `sources` (optional) – the sources that the user should be able to select elements from
     * - `criteria` (optional) – any specific element criteria parameters that should limit which elements the user can select
     * - `storageKey` (optional) – the localStorage key that should be used to store the element selector modal state (defaults to RedactorInput.LinkTo[ElementType])
     *
     * @param Element|null $element The element the field is associated with, if there is one
     * @return array
     */
    private function _getLinkOptions(Element $element = null): array
    {
        $linkOptions = [];

        $sectionSources = $this->_getSectionSources($element);
        $categorySources = $this->_getCategorySources($element);

        if (!empty($sectionSources)) {
            $linkOptions[] = [
                'optionTitle' => Craft::t('redactor', 'Link to an entry'),
                'elementType' => Entry::class,
                'refHandle' => Entry::refHandle(),
                'sources' => $sectionSources,
                'criteria' => ['uri' => ':notempty:']
            ];
        }

        if (!empty($this->_getVolumeKeys())) {
            $linkOptions[] = [
                'optionTitle' => Craft::t('redactor', 'Link to an asset'),
                'elementType' => Asset::class,
                'refHandle' => Asset::refHandle(),
                'sources' => $this->_getVolumeKeys(),
            ];
        }

        if (!empty($categorySources)) {
            $linkOptions[] = [
                'optionTitle' => Craft::t('redactor', 'Link to a category'),
                'elementType' => Category::class,
                'refHandle' => Category::refHandle(),
                'sources' => $categorySources,
            ];
        }

        // Give plugins a chance to add their own
        $event = new RegisterLinkOptionsEvent([
            'linkOptions' => $linkOptions
        ]);
        $this->trigger(self::EVENT_REGISTER_LINK_OPTIONS, $event);
        $linkOptions = $event->linkOptions;

        // Fill in any missing ref handles
        foreach ($linkOptions as &$linkOption) {
            if (!isset($linkOption['refHandle'])) {
                /** @var ElementInterface|string $class */
                $class = $linkOption['elementType'];
                $linkOption['refHandle'] = $class::refHandle() ?? $class;
            }
        }

        return $linkOptions;
    }

    /**
     * Returns the available section sources.
     *
     * @param Element|null $element The element the field is associated with, if there is one
     * @return array
     */
    private function _getSectionSources(Element $element = null): array
    {
        $sources = [];
        $sections = Craft::$app->getSections()->getAllSections();
        $showSingles = false;

        // Get all sites
        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($sections as $section) {
            if ($section->type === Section::TYPE_SINGLE) {
                $showSingles = true;
            } else if ($element) {
                $sectionSiteSettings = $section->getSiteSettings();
                foreach ($sites as $site) {
                    if (isset($sectionSiteSettings[$site->id]) && $sectionSiteSettings[$site->id]->hasUrls) {
                        $sources[] = 'section:' . $section->uid;
                    }
                }
            }
        }

        if ($showSingles) {
            array_unshift($sources, 'singles');
        }

        if (!empty($sources)) {
            array_unshift($sources, '*');
        }

        return $sources;
    }

    /**
     * Returns the available category sources.
     *
     * @param Element|null $element The element the field is associated with, if there is one
     * @return array
     */
    private function _getCategorySources(Element $element = null): array
    {
        $sources = [];

        if ($element) {
            $categoryGroups = Craft::$app->getCategories()->getAllGroups();

            foreach ($categoryGroups as $categoryGroup) {
                // Does the category group have URLs in the same site as the element we're editing?
                $categoryGroupSiteSettings = $categoryGroup->getSiteSettings();
                if (isset($categoryGroupSiteSettings[$element->siteId]) && $categoryGroupSiteSettings[$element->siteId]->hasUrls) {
                    $sources[] = 'group:' . $categoryGroup->uid;
                }
            }
        }

        return $sources;
    }

    /**
     * Returns the available volumes.
     *
     * @return string[]
     */
    private function _getVolumeKeys(): array
    {
        if (!$this->availableVolumes) {
            return [];
        }

        $criteria = ['parentId' => ':empty:'];

        $allVolumes = Craft::$app->getVolumes()->getAllVolumes();
        $allowedVolumes = [];
        $userService = Craft::$app->getUser();

        foreach ($allVolumes as $volume) {
            $allowedBySettings = $this->availableVolumes === '*' || (is_array($this->availableVolumes) && in_array($volume->uid, $this->availableVolumes));
            if ($allowedBySettings && ($this->showUnpermittedVolumes || $userService->checkPermission("viewVolume:{$volume->uid}"))) {
                $allowedVolumes[] = $volume->uid;
            }
        }

        $criteria['volumeId'] = Db::idsByUids('{{%volumes}}', $allowedVolumes);

        $folders = Craft::$app->getAssets()->findFolders($criteria);

        // Sort volumes in the same order as they are sorted in the CP
        $sortedVolumeIds = Craft::$app->getVolumes()->getAllVolumeIds();
        $sortedVolumeIds = array_flip($sortedVolumeIds);

        $volumeKeys = [];

        usort($folders, function($a, $b) use ($sortedVolumeIds) {
            // In case Temporary volumes ever make an appearance in RTF modals, sort them to the end of the list.
            $aOrder = $sortedVolumeIds[$a->volumeId] ?? PHP_INT_MAX;
            $bOrder = $sortedVolumeIds[$b->volumeId] ?? PHP_INT_MAX;

            return $aOrder - $bOrder;
        });

        foreach ($folders as $folder) {
            $volumeKeys[] = 'folder:' . $folder->uid;
        }

        return $volumeKeys;
    }

    /**
     * Get available transforms.
     *
     * @return array
     */
    private function _getTransforms(): array
    {
        if (!$this->availableTransforms) {
            return [];
        }

        $allTransforms = Craft::$app->getAssetTransforms()->getAllTransforms();
        $transformList = [];

        foreach ($allTransforms as $transform) {
            if (!is_array($this->availableTransforms) || in_array($transform->uid, $this->availableTransforms, false)) {
                $transformList[] = [
                    'handle' => Html::encode($transform->handle),
                    'name' => Html::encode($transform->name)
                ];
            }
        }

        return $transformList;
    }

    /**
     * Returns the available Redactor config options.
     *
     * @param string $dir The directory name within the config/ folder to look for config files
     * @return array
     */
    private function _getCustomConfigOptions(string $dir): array
    {
        $options = ['' => Craft::t('redactor', 'Default')];
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . $dir;

        if (is_dir($path)) {
            $files = FileHelper::findFiles($path, [
                'only' => ['*.json'],
                'recursive' => false
            ]);

            foreach ($files as $file) {
                $filename = basename($file);
                if ($filename !== 'Default.json') {
                    $options[$filename] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }
        
        ksort($options);

        return $options;
    }

    /**
     * Returns a JSON-decoded config, if it exists.
     *
     * @param string $dir The directory name within the config/ folder to look for the config file
     * @param string|null $file The filename to load.
     * @return array|false The config, or false if the file doesn't exist
     */
    private function _getConfig(string $dir, string $file = null)
    {
        if (!$file) {
            $file = 'Default.json';
        }

        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            if ($file !== 'Default.json') {
                // Try again with Default
                return $this->_getConfig($dir);
            }
            return false;
        }

        return Json::decode(file_get_contents($path));
    }

    /**
     * Returns the Redactor config used by this field.
     *
     * @return array
     */
    private function _getRedactorConfig(): array
    {
        if ($this->configSelectionMode === 'manual') {
            $config = Json::decode($this->manualConfig);
        } else {
            $config = $this->_getConfig('redactor', $this->redactorConfig) ?: [];
        }

        // Give plugins a chance to modify the Redactor config
        $event = new ModifyRedactorConfigEvent([
            'config' => $config,
            'field' => $this
        ]);

        $this->trigger(self::EVENT_DEFINE_REDACTOR_CONFIG, $event);

        return $event->config;
    }

    /**
     * Returns the HTML Purifier config used by this field.
     *
     * @return HTMLPurifier_Config
     */
    private function _getPurifierConfig(): HTMLPurifier_Config
    {
        $purifierConfig = HTMLPurifier_Config::createDefault();
        $purifierConfig->autoFinalize = false;

        $config = $this->_getConfig('htmlpurifier', $this->purifierConfig) ?: [
            'Attr.AllowedFrameTargets' => ['_blank'],
            'Attr.EnableID' => true,
            'HTML.AllowedComments' => ['pagebreak'],
            'HTML.SafeIframe' => true,
            'URI.SafeIframeRegexp' => '%^(https?:)?//(www.youtube.com/embed/|player.vimeo.com/video/)%',
        ];

        foreach ($config as $option => $value) {
            $purifierConfig->set($option, $value);
        }

        // Give plugins a chance to modify the HTML Purifier config, or add new ones
        $event = new ModifyPurifierConfigEvent([
            'config' => $purifierConfig,
        ]);

        $this->trigger(self::EVENT_MODIFY_PURIFIER_CONFIG, $event);

        return $event->config;
    }

    /**
     * Returns the allowed inline CSS styles, based on the plugins that are enabled.
     *
     * @return string[]
     */
    private function _allowedStyles(): array
    {
        $styles = [];
        $plugins = array_flip($this->_getRedactorConfig()['plugins'] ?? []);
        if (isset($plugins['alignment'])) {
            $styles['text-align'] = true;
        }
        if (isset($plugins['fontcolor'])) {
            $styles['color'] = true;
        }
        if (isset($plugins['fontfamily'])) {
            $styles['font-family'] = true;
        }
        if (isset($plugins['fontsize'])) {
            $styles['font-size'] = true;
        }
        return $styles;
    }
}
