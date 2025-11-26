<?php

namespace Fromholdio\PlainText\Extensions;

use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\ShortcodeParser;

/**
 * @mixin DataObject
 */
class PlainTextDataExtension extends Extension
{
    private static $is_plain_text_enabled = true;
    private static $plain_text_reset_on_changed_fields = [];
    private static $plain_text_part_names = [];

    private static $db = [
        'ContentPlain' => 'Text',
        'DoContentPlainReset' => 'Boolean',
        'PlainLastEdited' => 'DBDatetime'
    ];

    private static $defaults = [
        'DoContentPlainReset' => true
    ];


    public function getAsPlainText(bool $doUseCache = true): ?string
    {
        $text = null;
        if ($this->getOwner()->isPlainTextEnabled())
        {
            if ($doUseCache) {
                $text = $this->getOwner()->getDynamicData('ContentPlainText');
            }
            if (empty($text)) {
                if ($this->getOwner()->isPlainTextResetMarked()) {
                    $this->getOwner()->resetPlainText();
                    $myClass = get_class($this->getOwner());
                    $me = $myClass::get()->find('ID', $this->getOwner()->getField('ID'));
                    $text = $me?->getField('ContentPlain');
                }
                else {
                    $text = $this->getOwner()->getField('ContentPlain');
                }
            }
        }
        $this->getOwner()->invokeWithExtensions('updateAsPlainText', $text);
        $this->getOwner()->setDynamicData('ContentPlainText', $text);
        return $text;
    }


    /**
     * Regeneration process
     * ----------------------------------------------------
     */

    public function generatePlainText(): ?string
    {
        $text = null;
        $parts = $this->getOwner()->generatePlainTextParts();
        $parts = array_filter($parts);
        if (!empty($parts)) {
            $text = implode("\n\n", $parts);
        }
        return $text;
    }

    public function generatePlainTextParts(): array
    {
        $parts = [];
        $partNames = $this->getOwner()->getPlainTextPartNames();
        foreach ($partNames as $accessor => $type)
        {
            if (empty($accessor) || is_numeric($accessor)) {
                continue;
            }

            $output = null;
            $isMethod = false;
            $methodName = null;
            $parts[$accessor] = null;

            if (str_starts_with($accessor, '->')) {
                $isMethod = true;
                $methodName = mb_substr($accessor, 2);
                if (!$this->getOwner()->hasMethod($methodName)) {
                    continue;
                }
            }

            switch ($type)
            {
                case 'object':
                    $obj = $isMethod
                        ? $this->getOwner()->{$methodName}()
                        : $this->getOwner()->getComponent($accessor);
                    if ($obj?->exists() && $obj->hasExtension(self::class)) {
                        $output = $obj->getAsPlainText();
                    }
                    break;

                case 'list':
                    $list = $isMethod
                        ? $this->getOwner()->{$methodName}()
                        : $this->getOwner()->getComponents($accessor);
                    if ($list instanceof SS_List && $list->count() > 0)
                    {
                        $listParts = [];
                        foreach ($list as $listItem) {
                            if ($listItem->hasExtension(self::class)) {
                                $listParts[] = $listItem->getAsPlainText();
                            }
                        }
                        $listParts = array_filter($listParts);
                        if (!empty($listParts)) {
                            $output = implode("\n\n", $listParts);
                        }
                    }
                    break;

                case 'string':
                    $output = $isMethod
                        ? $this->getOwner()->{$methodName}()
                        : $this->getOwner()->getField($accessor);
                    break;

                case 'html':
                    $output = $isMethod
                        ? $this->getOwner()->{$methodName}()
                        : $this->getOwner()->getField($accessor);
                    $output = $this->getOwner()->convertHTMLPartToString($output);
                    break;

                default:
                    $this->getOwner()->invokeWithExtensions(
                        'generatePlainTextPart',
                        $output,
                        $accessor,
                        $type,
                        $isMethod,
                        $methodName
                    );
                    break;
            }
            $parts[$accessor] = $output;
        }
        $this->getOwner()->invokeWithExtensions('updateGeneratePlainTextParts', $parts);
        return $parts;
    }

    public function convertHTMLPartToString(?string $html): ?string
    {
        if (!empty($html)) {
            $html = ShortcodeParser::get_active()->parse($html);
            $html = Convert::html2raw(
                $html,
                false,
                0,
                $this->getOwner()->convertHTMLToPlainTextOptions()
            );
            $html = preg_replace("/\n\n\n+/", "\n\n", $html ?? '');
        }
        $this->getOwner()->invokeWithExtensions('updateDoConvertHTMLPartToString', $html);
        $html = trim($html ?? '');
        return empty($html) ? null : $html;
    }

    public function convertHTMLToPlainTextOptions(): array
    {
        $options = [
            'PreserveLinks' => false,
            'ReplaceBoldAsterisk' => false,
            'CompressWhitespace' => false,
            'ReplaceImagesWithAlt' => true,
        ];
        $this->getOwner()->invokeWithExtensions('updateConvertHTMLToPlainTextOptions', $options);
        return $options;
    }


    /**
     * Config accessors
     * ----------------------------------------------------
     */

    public function isPlainTextEnabled(): bool
    {
        $isEnabled = (bool) $this->getOwner()->config()->get('is_plain_text_enabled');
        $this->getOwner()->invokeWithExtensions('updateIsPlainTextEnabled', $isEnabled);
        return $isEnabled;
    }

    public function getPlainTextResetOnChangedFieldNames(): array
    {
        $fieldNames = $this->getOwner()->config()->get('plain_text_reset_on_changed_fields');
        $this->getOwner()->invokeWithExtensions('updatePlainTextResetOnChangedFieldNames', $fieldNames);
        return $fieldNames;
    }

    public function getPlainTextPartNames(): array
    {
        $parts = [];
        if ($this->getOwner()->isPlainTextEnabled()) {
            $parts = $this->getOwner()->config()->get('plain_text_part_names');
        }
        $this->getOwner()->invokeWithExtensions('updatePlainTextParts', $parts);
        return array_filter($parts);
    }


    /**
     * Reset conditions
     * ----------------------------------------------------
     */

    public function isPlainTextResetFieldsChanged(): bool
    {
        $fieldNames = $this->getOwner()->getPlainTextResetOnChangedFieldNames();
        $isChanged = false;
        foreach ($fieldNames as $fieldName) {
            $isChanged = $this->getOwner()->isChanged($fieldName);
            if ($isChanged) break;
        }
        $this->getOwner()->invokeWithExtensions('updateIsPlainTextResetFieldsChanged', $isChanged);
        return $isChanged;
    }

    public function isPlainTextResetMarked(): bool
    {
        return (bool) $this->getOwner()->getField('DoContentPlainReset');
    }


    /**
     * Reset handler
     * ----------------------------------------------------
     */

    public function resetPlainText(bool $doForceReset = false): void
    {
        $isEnabled = $this->getOwner()->isPlainTextEnabled();
        if (!$isEnabled) {
            return;
        }

        $children = $this->getOwner()->getPlainTextChildren();
        foreach ($children as $child) {
            if ($child->hasExtension(self::class)) {
                $child->resetPlainText($doForceReset);
            }
        }

        if (!$doForceReset && !$this->getOwner()->isPlainTextResetMarked()) {
            return;
        }

        $currStage = Versioned::get_stage();
        $tableName = $this->getOwner()->getPlainTextTableName();
        $id = $this->getOwner()->getField('ID');
        $now = DBDatetime::now()->getValue();
        if ($currStage === Versioned::LIVE) {
            $tableName .= '_Live';
        }

        $text = $this->getOwner()->generatePlainText() ?? '';
        $params = [$text, 0, $now, $id];

        $sql = "UPDATE \"$tableName\" SET \"ContentPlain\" = ?, \"DoContentPlainReset\" = ?, \"PlainLastEdited\" = ? WHERE \"ID\" = ?";
        DB::prepared_query($sql, $params);

        $this->getOwner()->invokeWithExtensions('onAfterPlainTextReset', $text);

        $parent = $this->getOwner()->getPlainTextParent();
        if ($parent?->hasExtension(self::class)) {
            if (!$parent->isPlainTextResetMarked()) {
                $parent->doMarkForPlainTextReset();
            }
        }

        $this->getOwner()->invokeWithExtensions('onAfterParentMarkedForPlainTextReset', $text);
    }

    public function doMarkForPlainTextReset(): void
    {
        $currStage = Versioned::get_stage();
        $tableName = $this->getOwner()->getPlainTextTableName();
        $params = [1, $this->getOwner()->getField('ID')];
        if ($currStage === Versioned::LIVE) {
            $tableName .= '_Live';
        }
        $sql = "UPDATE \"$tableName\" SET \"DoContentPlainReset\" = ? WHERE \"ID\" = ?";
        DB::prepared_query($sql, $params);

        $this->getOwner()->invokeWithExtensions('onAfterMarkedForPlainTextReset');

        $parent = $this->getOwner()->getPlainTextParent();
        $parent?->doMarkForPlainTextReset();
    }

    public function getPlainTextTableName(): string
    {
        /** @var DataObject $me */
        $me = $this->getOwner();
        return $me::getSchema()->tableForField(
            get_class($this->getOwner()),
            'ContentPlain'
        );
    }

    /**
     * @return DataObject&PlainTextDataExtension|null
     */
    public function getPlainTextParent(): ?DataObject
    {
        $parent = null;
        $this->getOwner()->invokeWithExtensions('updatePlainTextParent', $parent);
        return $parent;
    }

    public function getPlainTextChildren(): SS_List
    {
        $children = ArrayList::create();
        $this->getOwner()->invokeWithExtensions('updatePlainTextChildren', $children);
        return $children;
    }


    /**
     * Data processing and validation methods
     * ----------------------------------------------------
     */

    public function onAfterWrite(): void
    {
        $this->getOwner()->handlePlainTextChanges();
    }

    public function onAfterSkippedWrite(): void
    {
        $this->getOwner()->handlePlainTextChanges();
    }

    public function onAfterDelete(): void
    {
        $parent = $this->getOwner()->getPlainTextParent();
        if ($parent?->hasMethod('doMarkForPlainTextReset')) {
            $parent->doMarkForPlainTextReset();
        }
    }

    public function handlePlainTextChanges(): void
    {
        $doMarkForReset = false;
        if ($this->getOwner()->isPlainTextEnabled()) {
            if (!$this->getOwner()->isPlainTextResetMarked()) {
                $doMarkForReset = $this->getOwner()->isPlainTextResetFieldsChanged();
            }
        }
        $this->getOwner()->invokeWithExtensions(
            'updateDoPlainTextResetChangeCheck', $doMarkForReset
        );
        if ($doMarkForReset) {
            $this->getOwner()->doMarkForPlainTextReset();
        }
    }


    /**
     * CMS fields
     * ----------------------------------------------------
     */

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName([
            'ContentPlain',
            'DoContentPlainReset',
            'PlainLastEdited'
        ]);
    }


    /**
     * @return PlainTextDataExtension
     */
    public function getOwner()
    {
        /** @var self $owner */
        $owner = parent::getOwner();
        return $owner;
    }
}
