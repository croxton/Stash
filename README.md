#Stash

* Author: [Mark Croxton](http://hallmark-design.co.uk/)

## Version 2.3.2 beta

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
3. Create a folder to contain your Stash template files. Ideally this should be above the public webroot of your website.
4. Open your webroot index.php file, find the "CUSTOM CONFIG VALUES" section and add the following lines:

###
	
	$assign_to_config['stash_file_basepath'] = '/path/to/stash_templates/';
	$assign_to_config['stash_file_sync'] = TRUE; // set to FALSE for production
	$assign_to_config['stash_cookie'] = 'stashid'; // the stash cookie name
	$assign_to_config['stash_cookie_expire'] = 0; // seconds - 0 means expire at end of session
	$assign_to_config['stash_default_scope'] = 'user'; // default variable scope if not specified
	$assign_to_config['stash_limit_bots'] = TRUE; // stop database writes by bots to reduce load on busy sites
	$assign_to_config['stash_bots'] = array('bot', 'crawl', 'spider', 'archive', 'search', 'java', 'yahoo', 'teoma');


(of course if you're using a custom config bootstrap file, add the config items there instead)

## Upgrading

1. Copy the stash folder to ./system/expressionengine/third_party/
2. In the CP, navigate to Add-ons > Modules and click the 'Run module upgrades' link

## {exp:stash:set} tag pair

### Example usage:

	{exp:channel:entries limit="1" disable="member_data|pagination|categories"}	
		{exp:stash:set name="title"}{title}{/exp:stash:set}
	{/exp:channel:entries}

### name = [string]
The name of your variable (optional). 
This should be a unique name. Use underscores for spaces and use only alphanumeric characters.
Note: if you use the same variable twice the second one will overwrite the first.

### type = ['variable'|'snippet']
The type of variable to create (optional, default is 'variable').

#### `type = "variable"`
A variable is stored in the Stash session, and can be retrieved using `{exp:stash:get name="my_var"}` or `{exp:stash:my_var}`

#### `type = "snippet"`
A 'snippet' works just like snippets in ExpressionEngine, and can be used in the same way, e.g. `{my_var}`. When using snippets it is possible to overwrite existing EE global variables in the current request. Note that EE snippets can also be retrieved using `{exp:stash:get name="a_snippet" type="snippet"}`

### save = ['yes'|'no']
Do you want to store the variable in the database so that it persists across page loads? (optional, default is 'no')
Note that you should never save user data that is sensitive.

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

### site_id = [int]
When using MSM specify the site id here (optional). 
By default, the site_id of the site currently being viewed will be used.

### context = [string]
If you want to assign the variable to a context, add it here. 
Tip: Use '@' to refer to the current context:

	{exp:stash:set name="title" context="@"}
	...
	{exp:stash:set}

### scope = ['local'|'user'|'site']
Determines if an instance of the variable is set for the current page only, for the current user, or globally (set for everyone who visits the site) (optional, default is 'user').

#### `scope = "local"`
A 'local' variable exists in memory for the duration of the current HTTP request, and expires once the page has been rendered. It cannot be saved to the database.

#### `scope = "user"`
A 'user' variable behaves like a local variable but can optionally be saved to the database for persistence across page loads (with `save="yes"`). When saved, the variable is linked to the user's session id, and so it's content is only visible to that user. While a user-scoped variable can be set to expire after an arbitrary time period (with `refresh=""`), by default it will expire anyway once the users' session has ended. For persistence of user-scoped variables beyond the user's current browser session set the 'stash_cookie_expire' configuration setting to the desired number of seconds.

Use for pagination, search queries, or chunks of personalised content that you need to persist across pages. 

#### `scope = "site"`
A 'site' variable is set only once until it expires, and is visible to ALL site visitors. Use for caching common parts of your template.

### append = ['yes'|'no']
The value is appended to the existing variable. (optional, default is 'no'). Equivalent to using `{exp:stash:append}`

### prepend = ['yes'|'no']
The value is prepended to the existing variable. (optional, default is 'no'). Equivalent to using `{exp:stash:prepend}`

### no_results_prefix = [string]
Prefix for `{if no_results}...{/if}` for use in any *nested* tags:

	{exp:stash:set name="content" no_results_prefix="my_prefix" parse_tags="yes" output="yes"}
		{exp:channel:entries channel="blog" dynamic="no"}
			{if my_prefix:no_results}no results{/if}
		{/exp:channel:entries}
	{/exp:stash:set}
	
### Advanced example: caching a variable	
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

### Advanced example: using tag pairs to set multiple variables at once
`{exp:stash:set}` called WITHOUT a name="" parameter can be used to set multiple variables wrapped by tag pairs `{stash:variable1}...{/stash:variable1}` etc. These tag pairs can even be nested. 

In this example we want to ensure that the inner `{exp:channel:entries}` tag is parsed so we set `parse_tags="yes"`. Then we want to capture the the unordered list and the total count of entries so we can use them elsewhere in the same template:

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
Works the same as `{exp:stash:set}`, except the value is appended to an existing variable.

### Example usage of append, with match/against:

	{exp:channel:entries channel="people" sort="asc" orderby="person_lname"}	
		{exp:stash:append name="people_a_f" match="#^[A-F]#" against="{person_lname}"}
			<li><a href="/people/{entry_url}" title="view profile">{title}</a></li>
		{/exp:stash:append}
	{/exp:channel:entries}

The above would capture all 'people' entries whose last name `{person_lname}` starts with A-F.

## {exp:stash:prepend} tag pair	
Works the same as `{exp:stash:set}`, except the value is prepended to an existing variable.

## {exp:stash:set_value} single tag
Works the same as `{exp:stash:set}`, except the value is passed as a parameter. This can be useful for when you need to use a plugin as a tag parameter (always use with parse="inward"). For example:

	{exp:stash:set_value name="title" value="{exp:another:tag}" type="snippet" parse="inward"}

In this case `{title}` would be set to the parsed value of `{exp:another:tag}`

## {exp:stash:append_value} single tag
Works the same as `{exp:stash:append}`, except the value is passed as a parameter.

## {exp:stash:prepend_value} single tag
Works the same as `{exp:stash:prepend}`, except the value is passed as a parameter.
	
## {exp:stash:get}

### name = [string]
The name of your variable (required)

### type = ['variable'|'snippet']
The type of variable to retrieve (optional, default is 'variable').

### dynamic = ['yes'|'no']
Look in the $_POST and $_GET superglobals arrays, for the variable (optional, default is 'no').
If Stash doesn't find the variable in the superglobals, it will look in the uri segment array for the variable name and takes the value from the next segment, e.g.: /variable_name/variable_value

### file = ['yes'|'no'] 
[deprecated - use `{stash:embed}`]
Set to yes to tell Stash to look for a file in the Stash template folder (optional, default is 'no').
See [working_with_files](https://github.com/croxton/Stash/blob/dev/docs/working_with_files.md)

### file_name = [string] 
[deprecated - use `{stash:embed}`]       
The file name (without the extension) - only required if your filename is different from the variable name

### save = ['yes'|'no']
When using dynamic="yes" or file="yes", do you want to store the value we have retrieved in the database so that it persists across page loads? (optional, default is 'no')

### refresh = [int]
When using `save="yes"`, this parameter sets the number of minutes to store the variable (optional, default is 1440 - or one day)

### replace = ['yes'|'no']                
When using `dynamic="yes"` or `file="yes"`, do you want the variable to be overwritten if it already exists? (optional, default is 'yes')

### default = [string]
Default value to return if variable is not set or empty (optional, default is an empty string). If a default value is supplied and the variable has not been set previously, then the variable will be set in the user's session. Thus subsequent attempt to get the variable will return the default value specified by the first call.

### output = ['yes'|'no']
Do you want to output the variable or just get the variable quietly in the background? (optional, default is 'yes')

### site_id = [int]
When using MSM specify the site id here (optional). 
By default, the site_id of the site currently being viewed will be used. This parameter allows you to share variables across multiple sites.

### context = [string]
If the variable was defined within a context, set it here
Tip: you can also hardcode the context in the variable name, and use '@' to refer to the current context:

	{exp:stash:get name="title" context="@"}
	{exp:stash:get name="@:title"}
	{exp:stash:get name="news:title"}

### scope = ['local'|'user'|'site']
Is the variable set for the current page only, for the current user, or globally (set for everyone who visits the site) (optional, default is 'user').
Note: use the same scope that you used when you set the variable.

### process = ['inline'|'end']
When in the parse order of your EE template do you want the variable to be retrieved (default='inline')

#### `process="inline"`
Retrieve the variable in the natural parse order of the template (like a standard EE tag)

#### `process="end"`
Retrieve the variable at the end of template parsing after other tags and variables have been parsed

### priority = [int]
Determines the order in which the variable is retrieved when using process="end". Lower numbers are parsed first (default="1")

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
Has exactly the same parameters as `{exp:stash:get}`
Returns 0 or 1 depending on whether the variable is empty or not. Useful for conditionals.

### Example usage
	{if {exp:stash:not_empty name="my_variable" type="snippet"}}
		{my_variable}								
	{/if}

	
## {exp:stash:set_list} tag pair

Set an array of key/value pairs, defined by stash variable pairs {stash:my_key}my_value{/stash:my_key}.
Automatically detects and captures multiple rows of variables pairs, so can be used to capture data from tags and tag pairs that loop

* Accepts the same parameters as {exp:stash:set}, but in addition match/against can be used to match a value against a specific column in the list:

### match = [#regex#]
Match a column in the list against a regular expression. Only rows in the list that match will be appended to the list. 

### against = [list column]
Column to match against. If against is not specified or is not a valid list column, `match="#regex#"` will be applied to the whole block of tagdata passed to set_list.

### prefix = [string]
Prefix for `{if no_results}`, e.g. `{if my_prefix:no_results}`

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
	
### Example usage 3: capturing and caching Playa / Matrix tag pairs
	{exp:channel:entries channel="blog" entry_id="123"}
		{exp:stash:set_list name="blog_related_entries" parse_tags="yes" save="yes" scope="site"}
			{blog_related}
				{stash:item_title}{title}{/stash:item_title}
			{/blog_related}
		{/exp:stash:set_list}	
	{/exp:channel:entries}	
	
### Example usage 4: match against - set items where the topic title begins with 'A'
	{exp:stash:set_list name="recent_discussion_topics" parse_tags="yes" match="#^A#" against="topic_title"}
		{exp:forum:topic_titles 
			orderby="post_date" 
			sort="desc" 
			limit="5" 
			forums="1"
		}	
			{stash:topic_url}{thread_path='forum/viewthread'}{/stash:topic_url}
			{stash:topic_title}{title}{/stash:topic_title}
			{stash:last_author_url}{last_author_profile_path='member'}{/stash:last_author_url}
			{stash:last_author_name}{last_author}{/stash:last_author_name}
			{stash:last_post_date}{last_post_date}{/stash:last_post_date}
		{/exp:forum:topic_titles}
	{/exp:stash:set_list}	
	
### Example usage 5: nesting. Yep, you really can do this...

	{exp:stash:set_list name="my_entries" parse_tags="yes" parse_depth="2"}
		{exp:channel:entries channel="clients" limit="5"}

			{stash:entry_title}{title}{/stash:entry_title}
			{stash:entry_id}{entry_id}{/stash:entry_id}
		
			{exp:stash:set_list:nested name="related_entries_{entry_id}" parse_tags="yes"}
		
				{!-- this is a matrix tag pair --}
				{contact_docs}
					{stash:related_title}{mx_doc_title}{/stash:related_title}
				{/contact_docs}
			{/exp:stash:set_list:nested}	
		
		{/exp:channel:entries}	
	{/exp:stash:set_list}

	{exp:stash:get_list name="my_entries"}

		Entry title: {entry_title}
	
		Related:
		{exp:stash:get_list:nested name="related_entries_{entry_id}"}
			{related_title}
		{/exp:stash:get_list:nested}
	
	{/exp:stash:get_list}

### Example usage 6: handling no_results

no_results should ideally be handled by get_list, however it is possible to set a variable within the no_results block

    {exp:stash:set_list parse_tags="yes" name="my_entries"}
    	{exp:channel:entries 
    		channel="clients" 
    		limit="20"
    	}
    	    {stash:entry_title}{title}{/stash:entry_title}
			{stash:entry_id}{entry_id}{/stash:entry_id}
		
    		 {if no_results}
          		{exp:stash:set name="my_message"}No results{/exp:stash:set}
        	{/if}
	
    	{/exp:channel:entries}
    {/exp:stash:set_list}
    
    {exp:stash:get name="message" default="Showing results"}

    {exp:stash:get_list name="my_entries" limit="5"}
        {entry_title} ({entry_id})
    {/exp:stash:get_list}


## {exp:stash:append_list} tag pair

Append an array of variables to a list to create *multiple rows* of variables (i.e. a multidimensional array). 
If the list does not exist, it will be created.

* Accepts the same parameters as `{exp:stash:set}`

### Example usage
	{!-- set a list of entries in the products channel with a title that starts with the letter 'C' --}
	{exp:channel:entries channel="products" limit="5"}
   		{exp:stash:append_list name="product_entries" match="#^C#" against="{title}"}
     		{stash:item_title}{title}{/stash:item_title}
     		{stash:item_teaser}{product_teaser}{/stash:item_teaser}
 		{/exp:stash:append_list}
	{/exp:channel:entries}
	
## {exp:stash:prepend_list} tag pair

Prepend an array of variables to a list.

* Accepts the same parameters as `{exp:stash:set}`

### Example usage
	{exp:channel:entries channel="products"}
   		{exp:stash:prepend_list name="product_entries"}
     		{stash:item_title}{title}{/stash:item_title}
     		{stash:item_teaser}{product_teaser}{/stash:item_teaser}
 		{/exp:stash:prepend_list}
	{/exp:channel:entries}
	
## {exp:stash:get_list} tag pair

Retrieve a list and apply a custom order, sort, limit and offset. 

* Accepts the same parameters as {exp:stash:get} and the following:

### orderby = [string]
The variable you want to sort the list by.

### sort = ['asc'|'desc']
The sort order, either ascending (asc) or descending (desc) (optional, default is "asc").

### sort_type = [string|integer]
The data type of the column you are ordering by, either 'string' or 'integer' (optional, default is "string").

### limit = [int]
Limit the number of rows returned (optional).

### offset = [int]
Offset from 0 (optional, default is 0).

### match = [#regex#]
Match a column in the list against a regular expression. Only rows in the list that match will be returned.

### against = [list column]
Column to match against. If against is not specified or is not a valid list column, `match="#regex#"` will be applied to the whole string return by get_list.

### unique = ['yes'|'no']
Remove duplicate list rows (optional, default is 'no')

### process = ['inline'|'end']
When in the parse order of your EE template do you want the variable to be retrieved (default='inline')

#### `process="inline"`
Retrieve the variable in the natural parse order of the template (like a standard EE tag)

#### `process="end"`
Retrieve the variable at the end of template parsing after other tags and variables have been parsed

### priority = [int]
Determines the order in which the variable is retrieved when using process="end". Lower numbers are parsed first (default="1")

### paginate = ['bottom'|'top'|'both']
This parameter is for use with list pagination and determines where the pagination code will appear.

#### `paginate="top"` 
The navigation text and links will appear above your list.

#### `paginate="bottom"` 
The navigation text and links will appear below your list.

#### `paginate="both"` 
The navigation text and links will appear both above and below your list.

### paginate_base = [string]
Override the normal pagination link locations and point instead to the explicitly stated uri. This parameter is essential when using query string style pagination with `pagination_param=""`

### paginate_param = [string]
A parameter containing the page offset value. If set to a value, query-string style pagination links are created (e.g, ?page=P10) instead of the default segment style links (/P10); this can be useful when working with Structure / Page module uris. 

### prefix = [string]
Prefix for common iteration variables such as `{count}`, `{total:results}`, `{switch}` and `{if no_results}`. Useful when outputting a list inside another tag.

### single variables

* `{count}` - The "count" out of the row being displayed. If five rows are being displayed, then for the fourth row the {count} variable would have a value of "4".
* `{total_results}` -  the total number of rows in the list currently being displayed
* `{absolute_count}` - The absolute "count" of the current row being displayed by the tag, regardless of limit / offset.
* `{absolute_results}` - the absolute total number of rows in the list, regardless of limit / offset.
* `{switch="one|two|three"}` - this variable permits you to rotate through any number of values as the list rows are displayed. The first row will use "one", the second will use "two", the third "option_three", the fourth "option_one", and so on.

### pagination variables

Pagination variables use the same syntax as [Channel Entry pagination](http://expressionengine.com/user_guide/modules/channel/pagination_page.html)
The `{paginate}{/paginate}` tag pair can optionally be prefixed, if using `prefix=""`

### Example usage

	{exp:stash:get_list name="product_entries" orderby="item_title" sort="asc" limit="10"}
		<h2 class="{switch="one|two|three"}">{item_title}</h2>
   		<p>{item_teaser}</p>
		<p>This is item {count} of {total_results} rows curently being displayed.</p>
		<p>This is item {absolute_count} of {absolute_results} rows saved in this list</p>
	{/exp:stash:get_list}
	
### Advanced usage

	{exp:stash:get_list 
		name="recent_discussion_topics" 
		parse_tags="yes" 
		parse_conditionals="yes" 
		process="end" 
		prefix="my_prefix"
		paginate="bottom"
	}
		{if my_prefix:count == 1}
		<table class="data" cellpadding="0" cellspacing="0">

			<thead>
				<tr>
					<th class="left first">Title</th>
					<th>Last post</th>
					<th>Date</th>
				</tr>
			</thead>

			<tbody>
		{/if}
				<tr class="{my_prefix:switch='|rowAlt'}">
					<td class="left first"><a href="{topic_url}"><strong>{topic_title}</strong></a></td>
					<td><a href="{last_author_url}">{last_author_name}</a></td>
					<td>{last_post_date}</td>
				</tr>
		{if my_prefix:count == my_prefix:total_results}
			</tbody>
		</table>
		<p><a href="/forum/viewforum/{stash:forum}">View all topics in this forum &raquo;</a></p>
		{/if}
		
		{if my_prefix:no_results}
		<p>No forum topics yet. <a href="/forum/newtopic/{stash:forum}">Start a discussion &raquo;</a></p>
		{/if}
		
		{my_prefix:paginate}
    		{pagination_links}
    	    <ul>
    			{first_page}
    			        <li><a href="{pagination_url}" class="page-first">First Page</a></li>
    			{/first_page}

    			{previous_page}
    			        <li><a href="{pagination_url}" class="page-previous">Previous Page</a></li>
    			{/previous_page}

    			{page}
    			        <li><a href="{pagination_url}" class="page-{pagination_page_number} {if current_page}active{/if}">{pagination_page_number}</a></li>
    			{/page}

    			{next_page}
    			        <li><a href="{pagination_url}" class="page-next">Next Page</a></li>
    			{/next_page}

    			{last_page}
    			        <li><a href="{pagination_url}" class="page-last">Last Page</a></li>
    			{/last_page}
    	    </ul>
    		{/pagination_links}
    	{/my_prefix:paginate}

	{/exp:stash:get_list}		

## {exp:stash:unset} (requires PHP 5.2.3+) OR {exp:stash:destroy}
Unset an existing variable.

### name = [string]
The name of your variable (optional). If name is not passed, then ALL variables in the specified scope will be unset. 

### type = ['variable'|'snippet']
The type of variable to unset (optional, default is 'variable').

### scope = ['local'|'user'|'site']
The variable scope (optional, default is 'user').

### context = [string]
The variable namespace (optional)

### flush_cache = ['yes'|'no']
Delete the variable value from the database, if it has been saved (optional, default is 'yes').

### Example usage

	{!-- unset 'my_var' --}
	{exp:stash:unset name="my_var"}
	
	{!-- unset 'my_var' snippet --}
	{exp:stash:unset name="my_var" type="snippet"}
	
	{!-- unset all user-scoped variables --}
	{exp:stash:unset scope="user"}
	
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
	
	
## {stash:embed}

Embed a Stash template file in your template. Works similar to an EE embed, with the following advantages:

* Control over the process stage (when in the parse order of the host template that the embed is included)
* Control over the parse stage (whether the template is parsed and cached, or cached then parsed on retrieval, or both)
* Non caching regions of the template can be demarcated with `{stash:nocache}{/stash:nocache}` tag pairs
* Precisely control the order of processing of embeds with the priority parameter
* Precisely control the parse depth when a template is parsed (the number of passes made by EEs template parser)
* Set caching duration per embed
* Use multiple instances of the same template without extra overhead

### Setting up
* Make sure you follow the installation instructions (above) to set up a Stash template folder
* During development, set `stash_file_sync = TRUE` to keep your Stash template files in sync with the database
* For production use I highly recommend setting `stash_file_sync = FALSE` so that cached Stash templates are served from your database, unless you have added the replace="yes" parameter for a particular embed. Be careful to test first!

### Example usage

	{!-- Stash template file at /path/to/stash_templates/test.html --}
	{stash:embed name="test"}
	
	{!-- ...or using the shortcut syntax --}
	{stash:embed:test}
	
	{!-- Stash template file at /path/to/stash_templates/foo/bar.html --}
	{stash:embed name="foo:bar" process="start" stash:my_var="value"}
	
	{!-- could also be written as... --}
	{stash:embed context="foo" name="bar" process="start" stash:my_var="value"}
	
	{!-- ...or using the shortcut syntax --}
	{stash:embed:foo:bar process="start" stash:my_var="value"}

### name = [string]
The name of the template instance and the filename of your template (without the suffix), if file_name parameter is not set.

### context = [string]
The variable namespace, which must have a corresponding subfolder in the Stash template folder.	

### file_name = [string]
The file name (without the suffix) if different from the variable name, e.g. 'my_file' or 'my_context:my_file'.

### refresh = [int]
How long to cache the template output for in seconds (default='1440').

### replace = ['yes'|'no']
Do you want the cache to be recreated if it already exists? (default='no')
Note: set `stash_file_sync = true` in your EE config to override this value globally. You will need to do this during development.

### process = ['start'|'inline'|'end']
When in the parse order of your EE template do you want the embed to be included (default='end').

#### `process="start"`
Embed the template before any other variables and tags in your template are parsed (similar to an EE snippet).

#### `process="inline"`
Embed the template in the natural parse order of the template.

#### `process="end"`
Embed the template at the end of template parsing after other tags and variables have been parsed (like a standard EE embed).

### priority = [int]
Determines the order in which the template is parsed when using process="end". Lower numbers are parsed first (default="0").

### parse_stage = ['set'|'get'|'both']
When to parse the template: parse and cached, or cache then parsed on retrieval, or do both (default="get").

#### `parse_stage = "set"`
Parse the template the first time it is read from the file, and cache the rendered result. Subsequent retrievals will return the cached template from the database and not the original template file (unless replace="yes" or stash_file_sync = true).

#### `parse_stage = "get"`
Read the template file and cache it to the database. When output to the template on the first and subsequent retrievals the template will be parsed. This is similar to how EE templates work.

#### `parse_stage="both"`
Parse the template before caching AND after it is retrieved. This can be very useful when enclosing regions of your template with `{stash:nocache}{/stash:nocache}`. On SET the template code inside {stash:nocache} will not be parsed, but everything else will. On GET it will be parsed. This provides a way to partially cache some of your template code while leaving other areas dynamic.

### parse_depth = [int]
How many passes of the template to make by the parser? (default is 3)

### stash:my_variable="value"

Pass variables to the Stash template as parameters in the form `stash:my_variable="value"`:

	{exp:stash:embed name="my_template" stash:my_var="my_value"}
	
	{!-- inside my_template inline variables can be accessed like so --}
	{stash:my_var}	
	
### Advanced example: dynamic Stash embeds using the static context pointer '@'

	{!-- file at stash_templates/my_context/my_template.html --}

	{!-- standard embed syntax --}
	{stash:embed name="my_context:my_template"}

	{!-- shortcut embed syntax --}
	{stash:embed:my_context:my_template}

	{!-- set embed name dynamically from a Stash variable --}
	{stash:embed name="{stash:layout}"}
	{exp:stash:set_value name="layout" value="my_context:my_template"}
	
	{!-- using the static context pointer '@' --}
	{exp:stash:context name="my_context"}
	{stash:embed name="my_template" context="@"}
	
	{!-- or use the pointer in the name parameter --}
	{stash:embed name="@:my_template"}
	
	{!-- for multiple cached instances of same base template, use the file_name parameter --}
	{stash:embed name="another_instance_of_my_template" file_name="@:my_template"}
	
	{!-- 'template partials' example using dynamic context --}
	{stash:embed name="my_layout" context="@"}
	
	{exp:switchee variable="{segment_1}" parse="inward"}
	
	   {!-- left to right layout for latin languages --}
	   {case value="en|fr|it"}
	      {!-- template at stash_templates/ltr/my_layout.html --}
	      {exp:stash:context name="ltr"}
	      {exp:stash:set name="content"}My language specific content{/exp:stash:set}
	   {/case}
	
	   {!-- right to left layout for arabic --}
	   {case value="ar"}
	      {!-- template at stash_templates/rtl/my_layout.html --}
	      {exp:stash:context name="rtl"}
	      {exp:stash:set name="content"}بلدي محتوى لغة معينة{/exp:stash:set}
	   {/case}
	{/exp:switchee}	
	
## {exp:stash:parse} tag pair
Parse arbitrary regions of your template.

### process = ['inline'|'end']
When in the parse order of your EE template do you want the tags to be parsed (default='inline')

#### `process="inline"`
Parse the enclosed tagdata in the natural parse order of the template

#### `process="end"`
Parse the enclosed tagdata at the end of template parsing after other tags and variables have been parsed

### priority = [int]
Determines the order in which the enclosed tagadata is parsed when using process="end". Lower numbers are parsed first (default="1")

### parse_depth = [int]
How many passes of the enclosed tagdata to make by the parser? (default is 3)

## Shortcut tags

### {exp:stash:your_var_name} or {exp:stash:your_context:your_var_name}

On ExpressionEngine 2.5+, you may use this shortcut tag. When used as a single tag, this is equivalent to `{exp:stash:get name="your_var_name"}`. When used as a tag pair, this is equivalent to `{exp:stash:set name="your_var_name"}Hello World{/exp:stash:set}`.

### {stash:embed:your_template} or {stash:embed:your_context:your_template}

Alternative syntax for stash embeds for ExpressionEngine 2.5+ only.

