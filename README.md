# Silverstripe SS Template Engine

The template parsing code from [silverstripe/framework](https://github.com/silverstripe/silverstripe-framework), refactored into its own separate module. This is the first step to allowing developers to natively use whatever templating engine best suits their project.

The code in this repository will eventually be refactored, which will necessarily require breaking changes in framework to work correctly. It is an experiment primarily intended for my own learning - but if you want to play around with it as well you'll need to use the `experimental-view-refactor` branch in [my fork of silverstripe/framework](https://github.com/GuySartorelli/silverstripe-framework/tree/experimental-view-refactor).

## Not done yet

- Refactor `SSViewer_FromString` to work with any engine
  - It's called from `SSViewer::fromString()` which may need an extra parameter to indicate which parser to use (i.e. which filetype the string is for, 'ss', 'twig', etc)
  - `SSViewer_FromString` itself may not actually be necessary or perhaps could be moved to this module if it's .ss-specific

## Sub-optimal

- https://github.com/silverstripe/silverstripe-framework/issues/10404
- The `TemplateEngine` interface in framework currently requires a `SSViewer_Scope` which doesn't sit in framework anymore
  - Maybe move that back to framework
  - Maybe find a more generic way to represent the information that is actually needed across different engine types
- php-peg is in a thirdparty dir but should be accessed via composer

## Not covered in this project (so far)

- refactor `SilverStripe\i18n\TextCollection\Parser` to either check all engines or move it to this module
- `Email::setHTMLTemplate()` removes file extension, but is explicitly removing ".ss" instead of any template extension
- `YamlWriter::getClassKey()` does _something_ with ".ss" instead of any template extension
