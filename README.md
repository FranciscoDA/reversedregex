# reversedregex
Format a string so that it will match against a given PCRE

## Example usage
```php
require 'ReversedRegex.php';
$parser = new RegexParser('^/blog/(?<post_id>\d+)/.+$');
$reversed = new ReversedRegex($parser->regex()); // parse regex & reverse it
echo $reversed->format([
  'post_id' => 15,     // can be formatted by name or with index 0
  1 => 'My post title' // '.+' requires formatting too
]);
```
```
> /blog/15/My post title
```

### Which tokens need formatting
 * Character classes: `.`, `\w`, `\W`, `\d`, `\D`, `\s`, `\S`, `[abcdefg]`
 * Variable-length repetitions of literals and non-literals: _base_+, _base_*, _base_?, _base_{2,3}, base{2,}. Note that if _base_ is not a literal, you should provide a single formatting parameter to satisfy both _base_ and the repetition.
 * Fixed-length repetitions of non-literals: _base_{4}. Same as above
 * Reversed lookarounds: (?!abc), (?<!abc)

### Output verification
There's no verification at all. Consider the regex in the 'Example usage' section and the following code:
```php
echo $reversed->format(['post_id' => 'lol', 1 => '']);
```
```
> /blog/lol/
```
This will not match against the regex. You should use something like preg_match on the output if you need verification.

## What works as intended
Groups, named groups, character classes, lookarounds, anchors, string literals and repetitions

## What is not implemented
 * Alternations (i.e. pipe operator): It is not possible to determine which side of the operation to format without further inspection of the involved argument counts, types and matching tokens.
 ```php
 $p = new RegexParser('\d+|[a-zA-Z]+');
 $r = new ReversedRegex($p->regex());
 $r->format(['hello']); // which one?
 ```
 * Backreferences: There is not a 1:1 relation between groups and formatting placeholders in some cases. More specifically, PCRE dictates that when a group is repeated the backreference matches the last captured value of the group. However, we format both the group _and_ the repetition with a single value. The problem gets increasingly difficult when the group contains variable-length repetitions.
 ```php
 $p = new RegexParser('(\d)+\1');
 $r = new ReversedRegex($p->regex());
 $r->format(['123']); // (\d)+ formatted as '123', but how to tell what is '\1'?
 ```
