#Stash

* Author: [Mark Croxton](http://hallmark-design.co.uk/)

## Version 2.2.6 beta

This is the development version of Stash, and introduces Stash embeds and post/pre parsing of variables. Use with caution!

* Requires: ExpressionEngine 2.4+

## Description

Stash allows you to stash text and snippets of code for reuse throughout your templates. Variables can be set dynamically from the $_GET or $_POST superglobals and can optionally be cached in the database for persistence across pages.

Stash variables that you create are available to templates embedded below the level at which you are using the tag, or later in the parse order of the current template.

Stash is inspired by John D Wells' article on [template partials](http://johndwells.com/blog/homegrown-plugin-to-create-template-partials-for-expressionengine), and Rob Sanchez's [Dynamo](https://github.com/rsanchez/dynamo) module. 

## Key features
* Set, append, prepend and get variables
* Use regular expressions to filter variables that you stash
* Register variables from the $_POST, $_GET and uri segment arrays
* Save variables to the database for persistent storage across page loads
* Set variable scope (user or global)
* Parse tags contained within a Stash tag pair, so Stash saves the rendered output
* Individual control of parse depth, variable and conditional parsing when parsing tagdata
* Use contexts to namespace related groups of variables and help organise your code 
* save related form field values as a persistent 'bundle' of variables for efficient retrieval
* set, get append and prepend lists (multidimensional arrays ) - for example, Matrix and Playa data
* determine the order, sort, limit and offset when retrieving a list
* apply text tranformations and parsing to retrieved variables and lists
* Advanced uses: partial/full page caching, form field persistence, template partials/viewModel pattern implementation

## New in v2.2.0
* {stash:embed} - embed a Stash template file at different points in the parse order of the host template: start (before template parsing), inline (normal), end (post-process after normal template parsing has completed).
* Stash embeds can be nested, but unlike EE embeds you can use pre-parsing to create a single cache of the assembled templates (by setting parse_stage="set" on the parent embed). Which gives you the benefits of encapsulation without the overhead.
* {exp:stash:parse} - post-process arbitary sections of template code 
* {exp:stash:get} and {exp:stash:get_list} can be post processed.
* parse_stage="get|set|both" parameter for {exp:stash:set} allows saved variables to be post-parsed (get), pre-parsed (set) or both.
* The precise parse order of post-processed tags (get, get_list, parse, embed) can be determined with the new priority="" parameter
* {exp:stash:get_list} has a new prefix parameter for namespacing {count} and other common loop variables.
* Match against individual list key values when setting or getting a list, to filter the rows.

## Installation

1. Copy the stash folder to ./system/expressionengine/third_party/
2. In the CP, navigate to Add-ons > Modules and click the 'Install' link for the Stash module and the Stash extension

## Upgrading from <2.1.0 or earlier

1. Copy the stash folder to ./system/expressionengine/third_party/
2. In the CP, navigate to Add-ons > Modules and click the 'Run module upgrades' link for the Stash module
3. In the CP, navigate to Add-ons > Extensions and click the 'activate' link for the Stash extension

## Not updated the following yet...

## {exp:stash:set} tag pair

### name = [string]
The name of your variable (optional). 
This should be a unique name. Use underscores for spaces and use only alphanumeric characters.
Note: if you use the same variable twice the second one will overwrite the first.

### type = ['variable'|'snippet']
The type of variable to create (optional, default is 'variable').
A 'variable' is stored in the session, and must be retrieved using {exp:stash:get name=""}.
A 'snippet' works just like snippets in ExpressionEngine, and can be used directly as a tag {my_variable}
If using snippets, please be careful to namespace them so as not to overwrite any existing EE globals. 

### save = ['yes'|'no']
Do you want to store the variable in the database so that it persists across page loads? (optional, default is 'no')
Note that you should never cache any sensitive user data.

### refresh = [int]
The number of minutes to store the variable (optional, default is 1440 - or one day)

### replace = ['yes'|'no']                
Do you want the variable to be overwritten if it already exists? (optional, default is 'yes')

### output = ['yes'|'no']
Do you want to output the content inside the tag pair (optional, default is 'no')

### parse_tags = ['yes'|'no']
Do you want to parse any tags (modules or plugins) contained inside the stash tag pair (optional, default is 'no')

### parse_vars = ['yes'|'no']
Parse variables inside the template? (optional, default is 'yes', IF parse_tags is 'yes')

### parse_conditionals = ['yes'|'no']
Parse if/else conditionals inside the variable? (optional, default is 'no')

### parse_depth = [int]
How many passes of the template to make by the parser? (optional, default is 1) 
By default the parse depth is '1', meaning nested module tags would remain un-parsed. 
Set to a higher number to make repeated passes. More passes means more overhead (processing time).

### match = [#regex#]
Variable will only be set if the content matches the supplied regular expression (optional)

### against = [string]
String to match against if using the match= parameter (optional).
By default, the variable content is used.

### context = [string]
If you want to assign the variable to a context, add it here. 
Tip: Use '@' to refer to the current context:

	{exp:stash:set name="title" context="@"}
	...
	{exp:stash:set}


### scope = ['user'|'site']
When save ="yes", determines if the variable is locally scoped to the User's session, or globally (set for everyone who visits the site) (optional, default is 'user').

#### scope = "user"
A 'user' variable is linked to the users session id. Only they will see it. Use for pagination, search queries, or chunks of personalised content that you need to cache across multiple pages. 

#### scope = "site"
A 'global' variable is set only once until it expires, and is accessible to ALL site visitors. Use in combination with [Switchee](https://github.com/croxton/Switchee) for caching and reusing rendered content throughout your site.

### append = ['yes'|'no']
The value is appended to the existing variable. (optional, default is 'no'). Equivalent to using `{exp:stash:append}`

### prepend = ['yes'|'no']
The value is prepended to the existing variable. (optional, default is 'no'). Equivalent to using `{exp:stash:prepend}`

### Example usage:

	{exp:channel:entries limit="1" disable="member_data|pagination|categories"}	
		{exp:stash:set name="title" type="snippet"}{title}{/exp:stash:set}
	{/exp:channel:entries}
	
### Advanced usage 1	
Caching the output of a channel entries tag for 60 minutes. The first time the template is viewed the channel entries tag is run and its output is captured and saved to the database. On subsequent visits within the following 60 minutes, the output is retrieved from the database and the channel entries tag does NOT run. At the end of the 60 minutes  the variable expires from the database, and on the next view of the template the cache is regenerated. 

This approach can save you a huge number of queries and processing time.

	{exp:stash:set
		name="my_cached_blog_entries" 
		save="yes" 
		scope="site" 
		parse_tags="yes"
		replace="no" 
		refresh="60"
		output="yes"
	}
		{exp:channel:entries channel="blog"}
			<p>{title}</p>
		{/exp:channel:entries}
	{/exp:stash:set}

### Advanced usage 2
{exp:stash:set} called WITHOUT a name="" parameter can be used to set multiple variables wrapped by tag pairs {stash:variable1}....{/stash:variable1} etc. These tag pairs can even be nested. 

In this example we want to ensure that the inner {exp:channel:entries} tag is parsed so we set parse_tags="yes". Then we want to capture the the unordered list and the total count of entries so we can use them elsewhere in the same template:

	{exp:stash:set parse_tags="yes"}
		{stash:content}
		<ul>
		{exp:channel:entries channel="blog"}
			<li>{title}</li>
			{stash:absolute_results}{absolute_results}{/stash:absolute_results}
		{/exp:channel:entries}
		</ul>
		{/stash:content}
	{/exp:stash:set}

	Later in the same template or embedded template:

	{-- output the unordered list of entries --}
	{exp:stash:get name="content"}

	{-- output the absolute total count of the entries --}
	{exp:stash:get name="absolute_results"}


## {exp:stash:append} tag pair	
Works the same as {exp:stash:set}, except the value is appended to an existing variable.

### Example usage of append, with match/against:

	{exp:channel:entries channel="people" sort="asc" orderby="person_lname"}	
		{exp:stash:append name="people_a_f" match="#^[A-F]#" against="{person_lname}"}
			<li><a href="/people/{entry_url}" title="view profile">{title}</a></li>
		{/exp:stash:append}
	{/exp:channel:entries}

The above would capture all 'people' entries whose last name {person_lname} starts with A-F.

## {exp:stash:prepend} tag pair	
Works the same as {exp:stash:set}, except the value is prepended to an existing variable.

## {exp:stash:set_value} single tag
Works the same as {exp:stash:set}, except the value is passed as a parameter. This can be useful for when you need to use a plugin as a tag parameter (always use with parse="inward"). For example:

	{exp:stash:set_value name="title" value="{exp:another:tag}" type="snippet" parse="inward"}

In this case {title} would be set to the parsed value of {exp:another:tag}

## {exp:stash:append_value} single tag
Works the same as {exp:stash:append}, except the value is passed as a parameter.

## {exp:stash:prepend_value} single tag
Works the same as {exp:stash:prepend}, except the value is passed as a parameter.
	
## {exp:stash:get}

### name = [string]
The name of your variable (required)

### type = ['variable'|'snippet']
The type of variable to retrieve (optional, default is 'variable').

### dynamic = ['yes'|'no']
Look in the $_POST and $_GET superglobals arrays, for the variable (optional, default is 'no').
If Stash doesn't find the variable in the superglobals, it will look in the uri segment array for the variable name and takes the value from the next segment, e.g.: /variable_name/variable_value

### file = ['yes'|'no']
Set to yes to tell Stash to look for a file in the Stash template folder (optional, default is 'no').
See [working_with_files](https://github.com/croxton/Stash/blob/dev/docs/working_with_files.md)

### file_name = [string]        
The file name (without the extension) - only required if your filename is different from the variable name

### save = ['yes'|'no']
When using dynamic="yes" or file="yes", do you want to store the value we have retrieved in the database so that it persists across page loads? (optional, default is 'no')

### refresh = [int]
When using save="yes", this parameter sets the number of minutes to store the variable (optional, default is 1440 - or one day)

### replace = ['yes'|'no']                
When using dynamic="yes" or file="yes", do you want the variable to be overwritten if it already exists? (optional, default is 'yes')

### default = [string]
Default value to return if variable is not set or empty (optional, default is an empty string). If a default value is supplied and the variable has not been set previously, then the variable will be set in the user's session. Thus subsequent attempt to get the variable will return the default value specified by the first call.

### output = ['yes'|'no']
Do you want to output the variable or just get the variable quietly in the background? (optional, default is 'yes')

### context = [string]
If the variable was defined within a context, set it here
Tip: you can also hardcode the context in the variable name, and use '@' to refer to the current context:

	{exp:stash:get name="title" context="@"}
	{exp:stash:get name="@:title"}
	{exp:stash:get name="news:title"}

### scope = ['user'|'site']
Is the variable locally scoped to the User's session, or global (set for everyone who visits the site) (optional, default is 'user').
Note: use the same scope that you used when you set the variable.

### strip_tags = ['yes'|'no']
Strip HTML tags from the returned variable? (optional, default is 'no').

### strip_curly_braces = ['yes'|'no']
Strip curly braces ( { and } ) from the returned variable? (optional, default is 'no').

### backspace = [int]
Remove an arbitrary number of characters from the end of the returned string

### filter = [#(regex capture group)#]
Return only part of the string designated by the first capture group in a regular expression (optional)

	{!-- strip a trailing pipe character --}
	{exp:stash:get name="title" filter="#^(.*)\|$#"}

### Example usage

	{exp:stash:get name="title"}
	
### Advanced usage	
	Let's say you have a search form and you need to register a form field value and persist it across page views:
	
	{exp:stash:get name="my_form_field_name" type="snippet" dynamic="yes" cache="yes" refresh="30"}
	
	In an embedded template we have a {exp:channel:entries} tag producing a paginated listing of entries:
	{exp:channel:entries search:custom_field="{my_form_field_name}" disable="member_data|pagination|categories"}
		...
	{/exp:channel:entries}

## stash::get('name', 'type', 'scope') OR stash::get($array)

The get() method is also available to use in PHP-enabled templates using a static function call. With PHP enabled *on output*, this allows you to access the value of a variable at the end of the parsing, after tags have been processed and rendered. Note that you must use a stash tag somewhere in the template for this to work, and PHP must be enabled on OUTPUT.

You can also pass an array of key value pairs containing any of the parameters accepted by get.

### Example usage
	<?php echo stash::get('title') ?>
	
	<?php echo stash::get(array('name'=>'my_var', 'context' => '@')) ?>

	If you have short open tags enabled:
	<?= stash::get('title') ?>

## {exp:stash:context}
### name = [string]
The name of your context (required)

Set the current context (namespace) for variables that you set/get.

### Example usage

	{exp:stash:context name="news"}

	{!-- @ refers to the current context 'news' --}
	{exp:stash:set name="title" context="@" type="snippet"}
	My string
	{/exp:stash:set}

	{!-- now you can retrieve the variable in one of the following ways --}
	{exp:stash:get name="title" context="@" type="snippet"}
	{exp:stash:get name="title" context="news" type="snippet"}
	{exp:stash:get name="@:title" type="snippet"}
	{exp:stash:get name="news:title" type="snippet"}
	{@:title}

## {exp:stash:not_empty}
Has exactly the same parameters as {exp:stash:get}
Returns 0 or 1 depending on whether the variable is empty or not. Useful for conditionals.

### Example usage
	{if {exp:stash:not_empty name="my_variable" type="snippet"}}
		{my_variable}								
	{/if}

	
## {exp:stash:set_list} tag pair

Set an array of key/value pairs, defined by stash variable pairs {stash:my_key}my_value{/stash:my_key}

* Accepts the same parameters as {exp:stash:set}

### Example usage 1
	{exp:stash:set_list name="my_list"}
        {stash:item_title}My title{/stash:item_title}
 		{stash:item_summary}Summary text{/stash:item_summary}
        {stash:item_copy}Bodycopy goes here{/stash:item_copy}
    {/exp:stash:set_list}

### Example usage 2
	{exp:channel:entries channel="products" limit="1" entry_id="123"}
		{exp:stash:set_list name="my_product"}
	        {stash:item_title}{title}{/stash:item_title}
	 		{stash:item_summary}{summary}{/stash:item_summary}
	        {stash:item_copy}{copy}{/stash:item_copy}
	    {/exp:stash:set_list}
	{/exp:channel:entries}

## {exp:stash:append_list} tag pair

Append an array of variables to a list to create *multiple rows* of variables (i.e. a multidimensional array). 
If the list does not exist, it will be created.

* Accepts the same parameters as {exp:stash:set}

### Example usage
	{!-- set a list of entries in the products channel with a title that starts with the letter 'C' --}
	{exp:channel:entries channel="products" limit="5"}
   		{exp:stash:append_list name="product_entries" match="#^C#" against="{title}"}
     		{stash:item_title}{title}{/stash:item_title}
     		{stash:item_teaser}{product_teaser}{/stash:item_teaser}
 		{/exp:stash:append_list}
	{/exp:channel:entries}
	
### Advanced usage: caching lists
Generating a list of related items from a Playa custom field 'blog_related' and caching the result so that the Channel Entries and Playa tags do not run on subsequent views of the template:

	{exp:switchee variable="'{exp:stash:not_empty name='blog_related_entries' scope='site'}'" parse="inward"}
		{case value="'0'"}
			{exp:channel:entries channel="blog" entry_id="123"}
				{blog_related}
					{exp:stash:append_list name="blog_related_entries" save="yes" scope="site"}
						{stash:item_title}{title}{/stash:item_title}
					{/exp:stash:append_list}	
				{/blog_related}
			{/exp:channel:entries}
		{/case}	
	{/exp:switchee}
	
## {exp:stash:prepend_list} tag pair

Prepend an array of variables to a list.

* Accepts the same parameters as {exp:stash:set}

### Example usage
	{exp:channel:entries channel="products"}
   		{exp:stash:prepend_list name="product_entries"}
     		{stash:item_title}{title}{/stash:item_title}
     		{stash:item_teaser}{product_teaser}{/stash:item_teaser}
 		{/exp:stash:prepend_list}
	{/exp:channel:entries}
	
## {exp:stash:get_list} tag pair

Retrieve a list and apply a custom order, sort, limit and offset.

### orderby = [string]
The variable you want to sort the list by.

### sort = [asc|desc]
The sort order, either ascending (asc) or descending (desc) (optional, default is "asc").

### sort_type = [string|integer]
The data type of the column you are ordering by, either 'string' or 'integer' (optional, default is "string").

### limit = [int]
Limit the number of rows returned (optional).

### offset = [int]
Offset from 0 (optional, default is 0).

### variables

* {count} - The "count" out of the row being displayed. If five rows are being displayed, then for the fourth row the {count} variable would have a value of "4".
* {total_results} -  the total number of rows in the list currently being displayed
* {absolute_count} - The absolute "count" of the current row being displayed by the tag, regardless of limit / offset.
* {absolute_results} - the absolute total number of rows in the list, regardless of limit / offset.
* {switch="one|two|three"} - this variable permits you to rotate through any number of values as the list rows are displayed. The first row will use "one", the second will use "two", the third "option_three", the fourth "option_one", and so on.

### Example usage
	{exp:stash:get_list name="product_entries" orderby="item_title" sort="asc" limit="10"}
		<h2 class="{switch="one|two|three"}">{item_title}</h2>
   		<p>{item_teaser}</p>
		<p>This is item {count} of {total_results} rows curently being displayed.</p>
		<p>This is item {absolute_count} of {absolute_results} rows saved in this list</p>
	{/exp:stash:get_list}	
	
## {exp:stash:flush_cache}
Add this tag to a page to clear all cached variables. You have to be logged in a Super Admin to clear the cache.
	
## {exp:stash:bundle} tag pair	
Bundle up a group of independent variables into a single variable and save to the database. If the bundled variable already exists then the bundle is retrieved and the individual variables are restored into the current session.

This is very useful when working with form field values that you want to register from a dynamic source such as $_POST or $_GET, validate the user submitted form value against a regular expression, and persist the submitted value from page to page. Instead of saving multiple variables you can efficiently bundle them up into one variable requiring only a single query for retrieval.

### name = [string]
The name of your bundle (required)

### unique = ['yes'|'no']
Do you only want to allow one entry per bundle? (optional, default is 'no')

### context = [string]
Defined a context for the bundled variables (optional)

### refresh = [int]
This parameter sets the number of minutes to store the bundle (optional, default is 1440 - or one day)

### Example usage

	{exp:stash:context name="my_search_form"}
	
	{exp:stash:bundle name="form" context="@" refresh="10" parse="inward"}
		{exp:stash:get dynamic="yes" type="snippet" name="orderby" output="yes" default="entry_date" match="#^[a-zA-Z0-9_-]+$#"}
		{exp:stash:get dynamic="yes" type="snippet" name="sort" output="yes" default="asc" match="#^asc$|^desc$#"}
		{exp:stash:get dynamic="yes" type="snippet" name="category" output="yes" default="" match="#^[a-zA-Z0-9_-]+$#"}
	{/exp:stash:bundle}
	
	{!-- now you could use like this in an embedded view template --}
	<input name="orderby" value="{@:orderby}">

## {exp:stash:your_var_name}

On ExpressionEngine 2.5+, you may use this shortcut tag. When used as a single tag, this is equivalent to `{exp:stash:get name="your_var_name"}`. When used as a tag pair, this is equivalent to `{exp:stash:set name="your_var_name"}Hello World{/exp:stash:set}`.