# F3 Validation engine

This is an extension for the [PHP Fat-Free Framework](https://github.com/bcosca/fatfree) that offers a validation system, especially tailored for validating [Cortex](https://github.com/ikkez/f3-cortex) models.
The validation system is based on the well known [GUMP](https://github.com/Wixel/GUMP) validator, with additional sugar of course:

*  Validator based on GUMP
*  multi-level validation
*  define Cortex validation within `$fieldConf`
*  nested array & dependency validation
*  pre- & post-validation filters
*  F3-compatible language dictionaries included
*  context-based language overwrites per field
*  also usable without Cortex
*  it's extendable

## Table of Contents

1. [Installation](#installation)
2. [Getting started](#getting-started)
3. [Validation](#validation)
	1. [Cortex Mapper Validation](#cortex-mapper-validation)
	2. [Data Array Validation](#data-array-validation)
4. [Rules](#rules)
	1. [Validators](#validators)
	2. [Filters](#filters)
	3. [Validation Dependencies](#validation-dependencies)
	4. [Validation Level](#validation-level)
	5. [Array Validation](#array-validation)
	6. [Contains Check](#contains-check)
5. [Error handling](#error-handling)
	1. [Error context](#error-context)
	2. [Field names](#field-names)
	3. [Frontend integration](#frontend-integration)
	4. [Custom Errors](#custom-errors)
6. [Extend](#extend)
7. [License](#license)


## Installation

To install with composer, just run `composer require ikkez/f3-validation-engine`. 
In case you do not use composer, add the `src/` folder into your `AUTOLOAD` path and install [Wixel/GUMP](https://github.com/Wixel/GUMP) separately.


## Getting started


By default, the validation engine is silent. In order to make use of errors that where found during validation, you have to wire some things together. 
This is done in the `onError` handler, where you define what to do with errors. An example:

```php
$validation = \Validation::instance();

$validation->onError(function($text,$key) {
	\Base::instance()->set('error.'.$key, $text);
	\Flash::instance()->addMessage($text,'danger');
});
```

In the example above, we've registered an onError handler that sets an error key in the Hive as well as adding a flash message to the user Session. Feel free to define this globally or change the onError handler on the fly.

For the error text to appear correctly, you need to load the included language dictionaries. To do so, you can add the lang folders to your dictionary path, like 

```ini
LOCALES = app/your/lang/,vendor/ikkez/f3-validation-engine/src/lang,vendor/ikkez/f3-validation-engine/src/lang/ext,
```

Or you simply call a little helper for this one:

```php
\Validation::instance()->loadLang();
```

This uses an optimized loading method and also includes some caching technique when you give `$ttl` as 1st argument.

NP: Caching like `\Validation::instance()->loadLang(3600*24);` requires the latest F3 edge-version, or it can lead to weird lexicon issues. Use at least:

`"bcosca/fatfree-core": "dev-master#b3bc18060f29db864e00bd01b25c99126ecef3d6 as 3.6.6"`

## Validation

To use the validation engine, you can choose between a simple array validation against a defined set of rules or take a cortex mapper with predefined rules in its field configuration.

### Cortex Mapper Validation

To use the mapper validation, you have to define the validation rules in the `$fieldConf` array. This can look like this:

```php
protected $fieldConf = [
	'username' => [
		'type' => Schema::DT_VARCHAR128,
		'filter' => 'trim',
		'validate' => 'required|min_len,10|max_len,128|unique',
	],
	'email' => [
		'type' => Schema::DT_VARCHAR256,
		'filter' => 'trim',
		'validate' => 'required|valid_email|email_host',
	],
];
```

To start validation you can either use this method:

```php
$valid = $validation->validateCortexMapper($mapper);
```

or add the appropriate validation trait to your model class:

```php
namespace Model;
class User extends \DB\Cortex {

	use \Validation\Traits\CortexTrait;
	
	// ...
} 
```

and then just call `$mapper->validate();` on your model directly. 

### Data Array Validation

If you just have a simple array and want to validate that against some rules you can do so too:

````php
$data = [
  'username' => 'Jonny',
  'email' => 'john.doe@domain.com',
];
$rules = [
  'username' => [
    'filter' => 'trim',
    'validate' => 'required|min_len,10|max_len,128|unique',
  ],
  'email' => [
    'filter' => 'trim',
    'validate' => 'required|valid_email|email_host',
  ]
];
$valid = $validation->validate($rules, $data);
````

---

The validation methods always return a boolean value to indicate if the data is valid (`TRUE`) or any error was found (`FALSE`); 


## Rules

### Validators

The default validators from GUMP are:

*	`required`  
	Ensures the specified key value exists and is not empty
*	`valid_email`  
	Checks for a valid email address
*	`max_len,n`  
	Checks key value length, makes sure it's not longer than the specified length. n = length parameter.
*  `min_len,n`  
	Checks key value length, makes sure it's not shorter than the specified length. n = length parameter.
*	`exact_len,n`  
	Ensures that the key value length precisely matches the specified length. n = length parameter.
*	`alpha`  
	Ensure only alpha characters are present in the key value (a-z, A-Z)
*	`alpha_numeric`  
	Ensure only alpha-numeric characters are present in the key value (a-z, A-Z, 0-9)
*	`alpha_dash`  
	Ensure only alpha-numeric characters + dashes and underscores are present in the key value (a-z, A-Z, 0-9, _-)
*	`alpha_space`  
	Ensure only alpha-numeric characters + spaces are present in the key value (a-z, A-Z, 0-9, \s)
*	`numeric`  
	Ensure only numeric key values
*	`integer`  
	Ensure only integer key values
*	`boolean`  
	Checks for PHP accepted boolean values, returns TRUE for "1", "true", "on" and "yes"
*	`float`  
	Checks for float values
*	`valid_url`  
	Check for valid URL or subdomain
*	`url_exists`  
	Check to see if the url exists and is accessible
*	`valid_ip`  
	Check for valid generic IP address
*	`valid_ipv4`  
	Check for valid IPv4 address
*	`valid_ipv6`  
	Check for valid IPv4 address
*	`guidv4`  
	Check for valid GUID (v4)
*	`valid_cc`  
	Check for a valid credit card number (Uses the MOD10 Checksum Algorithm)
*	`valid_name`  
	Check for a valid format human name
*	`contains,n`  
	Verify that a value is contained within the pre-defined value set
*	`contains_list,n`  
	Verify that a value is contained within the pre-defined value set. The list of valid values must be provided in semicolon-separated list format (like so: value1;value2;value3;..;valuen). If a validation error occurs, the list of valid values is not revelead (this means, the error will just say the input is invalid, but it won't reveal the valid set to the user.
*	`doesnt_contain_list,n`  
	Verify that a value is not contained within the pre-defined value set. Semicolon (;) separated, list not outputted. See the rule above for more info.
*	`street_address`  
	Checks that the provided string is a likely street address. 1 number, 1 or more space, 1 or more letters
*	`date`  
	Determine if the provided input is a valid date (ISO 8601)
*	`min_numeric`  
	Determine if the provided numeric value is higher or equal to a specific value
*	`max_numeric`  
	Determine if the provided numeric value is lower or equal to a specific value
*	`min_age,n`  
	Ensure that the field contains a date with a minimum age, i.e. `min_age,18`. Input date needs to be in ISO 8601 / `strtotime` compatible.
*	`starts,n`  
	Ensures the value starts with a certain character / set of character
*	`equalsfield,n`  
	Ensure that a provided field value equals current field value.
*	`iban`  
	Check for a valid IBAN
*	`phone_number`  
	Validate phone numbers that match the following examples: 555-555-5555 , 5555425555, 555 555 5555, 1(519) 555-4444, 1 (519) 555-4422, 1-555-555-5555
*	`regex`  
	You can pass a custom regex using the following format: `'regex,/your-regex/'`
*	`valid_json_string`  
	validate string to check if it's a valid json format


**Additional validators:**


*	`empty`  
	Check if a field value is `empty`.
*	`notempty`  
	Ensure that a field value is not `empty`.
*	`email_host`  
	Checks the MX domain record from a given email address. Fails if the host system does not accept emails. This validator does only work on valid emails, but does not validate the email address itself (combine this with `valid_email`).
*	`unique` *- Cortex only -*  
	Check if the given field value is unique in the database table (i.e. for usernames).


### Filters

Filters can be used to sanitize the input data before validating it. Basically a filter is a simple PHP function that returns a string. 

You can set up normal a `filter` that is applied before validation and `post_filter` that's applied afterwards:

```php
'foo' => [
	'filter' => 'trim|base64_decode',
	'validate' => 'required|valid_url',
	'post_filter' => 'base64_encode',
],
```

Default GUMP filters:

*	`sanitize_string`  
	Remove script tags and encode HTML entities, similar to GUMP::xss_clean();
*	`urlencode`  
	Encode url entities`
*	`htmlencode`  
	Encode HTML entities
*	`sanitize_email`  
	Remove illegal characters from email addresses
*	`sanitize_numbers`  
	Remove any non-numeric characters
*	`sanitize_floats`  
	Remove any non-float characters
*	`trim`  
	Remove spaces from the beginning and end of strings
*	`base64_encode`  
	Base64 encode the input
*	`base64_decode`  
	Base64 decode the input
*	`sha1`  
	Encrypt the input with the secure sha1 algorithm
*	`md5`  
	MD5 encode the input
*	`noise_words`  
	Remove noise words from string
*	`json_encode`  
	Create a json representation of the input
*	`json_decode`  
	Decode a json string
*	`rmpunctuation`  
	Remove all known punctuation characters from a string
*	`basic_tags`  
	Remove all layout orientated HTML tags from text. Leaving only basic tags
*	`whole_number`  
	Ensure that the provided numeric value is represented as a whole number
*	`ms_word_characters`  
	Converts MS Word special characters [“”‘’–…] to web safe characters
*	`lower_case`  
	Converts to lowercase
*	`upper_case`  
	Converts to uppercase
*	`slug`  
	Creates web safe url slug

**Additional filters:**

*	`website`  
	Prepend `http://` protocol to URI, if no protocol was given.


### Validation Dependencies

You can skip a validation rule and only take it into account when a certain condition to another field is met.

In the following example, `foo` is only required when `bar = 2`:

```php
'foo' => [
	'validate' => 'required',
	'validate_depends' => [
		'bar' => 2
	]
]
```

You can also use some more advanced dependency checks, like nested validation rules:

```php
'foo' => [
	'validate' => 'required',
	'validate_depends' => [
		'bar' => ['validate', 'min_numeric,2']
	]
]
```

When the nested validation is `TRUE` (valid), then the dependency is met and the validation of `foo` goes on, otherwise it's skipped.
If that's not enough, you can also add a custom function:

```php
'validate_depends' => [
	'bar' => ['call', function($value,$input) { return (bool) $value % 3 }]
]
```

An F3 callstring like `['call', 'Foo->bar']` is also possible.


### Validation Level

Similar to a validation dependency, you can also define validation levels. 
If a validation level is defined of a field, its validation is completely skipped (filters however are applied).
You can define a validation level like this:

```php
'foo' => [
	'validate_level'=>2,
	'validate' => 'required',
]
```

By default, all validations are made with `level = 0`. To trigger this validation rule, you need to call the validate function with a new `$level`:

```php
$validation->validateCortexMapper($mapper, $level);
// OR
$validation->validate($rules, $data, $level);
```

The default comparison operator for the level check is `<=`. So a level-2 validation also triggers level 0 and level 1 validation rules. If you only want to check level-2 alone, set a new `$level_op` parameter as well. 

### Array Validation

In case you have an array field in your data or model, you can validate certain array keys as well:

```php
'contact' => [
	'type' => self::DT_JSON,
	'validate_array' => [
		'name' => ['validate'=>'required'],
		'address' => ['validate'=>'required|street_address'],
		'region' => ['validate'=>'required'],
		'zip' => ['validate'=>'required'],
	]
]
```

**Nested arrays:**

It's also possible to validate a nested array / an array of assoc arrays: 

```php
'partners' => [
	'type' => self::DT_JSON,
	'validate_nested_array' => [
		'name' => ['validate'=>'required'],
		'profession' => ['validate'=>'required'],
		'phone' => ['validate'=>'required'],
		'image' => ['filter'=>'upload'],
	]
]
```

### Contains check

*- work in progress -*

It is also possible to define the values that *contains* will check (`contains` validator) with a simple `item` key in your `$fieldConf` (or `$rules` array):

```php
'foo' => [
	'type' => Schema::DT_VARCHAR128,
	'item' => [
		'one',
		'two',
		'three',
	]
]
```

If the value does not match one of the items defined, the validation will fail. It's also possible to put a string to `item`, which is a F3 hive key, that contains the array with items.

## Error handling

Error messages are loaded from the dictionary files into the F3 hive. 

The default hive location is at: `{PREFIX}error.validation.{type}`

That means, in case your [PREFIX](https://fatfreeframework.com/3.6/quick-reference#PREFIX) is `ll.`, the default text for the `required` validator is at `ll.error.validation.required`.
Of course you can overwrite that in your custom language files for the whole project, but sometimes you might only want to change that for a single field in your model. Here we can use **error contexts**.

### Error context

When you validate a Cortex model, it'll automatically have a context according to the class namespace. So in case you validate `\Model\User`, the error context is at `error.model.user`.

The trick to overwrite the default error message for a **specific field** and a **specific validator** is to create a new language key at this context:

```ini
[error.model.user]
username.required = You Shall Not Pass!
username.unique = Doh! It's already taken.
```

### Field names 

Most error messages contain a field identifier / field name, the error belongs to. By default this name is build from the array key of that field but that's mostly not enough. To have a proper translated field name in your error message too, the system looks for a language key according to the model context and the field. 

In example, our username field in user model should be labeled at:

`model.user.username.label = Nick name`  

While you're at it, you could also think about placeholder labels, help text and more that might fit into this schema, which can potentially improve the frontend wiring as well.

When you get an error within validating an array using the `validate_array` or `validate_nested_array` rules, the field labels are moved one key below the entry field. I.e. when you have an address field on your user model that includes a zip-code field, the label context would be:
  
`model.user.address.zip.label = Zip Code`


### Frontend integration

When you've set the error key within the `onError` handler like in the sample from the beginning, you can easily use those to display custom error messages or add classes to your markup:

```html
<div class="uk-card uk-card-default {{ @@error.model.user.username ? 'frame-red' : 'frame-grey' }}">
  <div class="uk-card-body">
    <h3 class="uk-card-title">{{ @ll.model.user.username.label }}</h3>
    <div class="uk-margin">
      <input class="uk-input {{ @@error.model.user.username ? 'uk-form-danger' : '' }}" 
        type="text" name="username" placeholder="{{ @@ll.model.user.username.placeholder }}">
    </div>
  </div>
</div>
```

If you like, you can also add customized help texts depending on the failed validator. 

For example add a `<F3:check if="{{@@error.model.user.username.unique}}">` or a different one for the required validator and you can build complex and functional form states. For additional flash messages, maybe have a look at [f3-flash](https://github.com/ikkez/f3-flash/).


### Custom errors

If you want to set a custom error message only, read about **Error context** above.

In case you need to trigger an error manually, because there are parts to validate that are out of scope here at the moment, you can make your own validation and emit an error yourself like this:

```php
$valid = $userModel->validate();

if (!$userModel->foo1 && !$userModel->foo2) {
  \Validation::instance()->emitError('foo','required','model.user.foo');
  $valid = false;
}
if ($valid) {
  // continue
}
```


## Extend

Extending the system is easy. To add a new **validator**:

```php
\Validation::instance()->addValidator('is_one', function($field, $input, $param=NULL) {
	return $input[$field] === 1;
}, 'The field "{0}" must be 1');
```

Also add the new translation key at `error.validation.is_one`, otherwise only the fallback text is shown, or the context, when no text was given at all.

And to add a new **filter**:

```php
\Validation::instance()->addFilter('nl2br',function($value,$params=NULL) {
	return nl2br($value);
});
```

You can also use a F3 callstring too:

```php
$validator = \Validation::instance(); 
$validator->addValidator('is_one','App\MyValidators->is_one');
$validator->addFilter('nl2br','App\MyFilters->nl2br');
```


License
-

GPLv3
