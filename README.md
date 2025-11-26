# silverstripe-plain-text

Takes a Data Object (most often a Page) and, using the configuration you set, converts the content of the Data Object to plain text, and saves into ContentPlain field.

For example, with the right configuration, the ContentPlain field will contain a plain text version of the main content of a Page, with all shortcodes replaced with their content, all HTML tags removed/replaced, derived from either or both of the page's Content field and/or the visible Elements inside any relevant ElementalAreas.

This module can be used in conjunction with [fromholdio/silverstripe-text-statistics](https://github.com/fromholdio/silverstripe-text-statistics), which can then use the ContentPlain field to calculate statistics on the content, such as word count, reading time, readability scores/standards, etc.

Alternatively or additionally, this single plain text field can be used for fast full-text searching of the content; and/or for deriving a summary of the content for use in search results, metadata, etc.

Requires Silverstripe 6+ (see branch 1.x for Silverstripe 4 & 5 support.)

Docs and examples of configurations to achieve the above to come.
