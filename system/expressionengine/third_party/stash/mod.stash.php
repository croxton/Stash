<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once PATH_THIRD . 'stash/config.php';

/**
 * Set and get template variables, EE snippets and persistent variables.
 *
 * @package             Stash
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2012 Hallmark Design
 * @license             http://creativecommons.org/licenses/by-nc-sa/3.0/
 * @link                http://hallmark-design.co.uk
 */

class Stash {

    public $EE;
    public $version = STASH_VER;
    public $site_id;
    public $path;
    public $file_sync;
    public $stash_cookie;
    public $stash_cookie_expire;
    public $default_scope;
    public $limit_bots;
    public static $context = NULL;
    
    protected $xss_clean;
    protected $replace;
    protected $type;
    protected $parse_tags = FALSE;
    protected $parse_vars = NULL;
    protected $parse_conditionals = FALSE;
    protected $parse_depth = 1;
    protected $parse_complete = FALSE;
    protected $bundle_id = 1;
    protected $process = 'inline';
    protected $priority = 1;
    protected static $bundles = array();
    
    private $_update = FALSE;
    private $_append = TRUE;
    private $_stash;
    private $_session_id;
    private $_ph = array();
    private $_list_delimiter = '|+|';
    private $_list_row_delimiter = '|&|';
    private $_list_row_glue = '|=|';
    private $_list_null = '__NULL__';
    private $_embed_nested = FALSE;
    private static $_is_human = TRUE;

    /*
     * Constructor
     */
    public function __construct($calling_from_hook = FALSE)
    {
        $this->EE =& get_instance();
        
        // load dependencies - make sure the package path is available in case the class is being called statically
        $this->EE->load->add_package_path(PATH_THIRD.'stash/', TRUE);
        $this->EE->lang->loadfile('stash');
        $this->EE->load->model('stash_model');

        // default site id
        $this->site_id = $this->EE->config->item('site_id');
        
        // config defaults
        $this->path                 = $this->EE->config->item('stash_file_basepath') ? $this->EE->config->item('stash_file_basepath') : APPPATH . 'stash/';
        $this->file_sync            = $this->EE->config->item('stash_file_sync')     ? $this->EE->config->item('stash_file_sync') : FALSE;
        $this->stash_cookie         = $this->EE->config->item('stash_cookie')        ? $this->EE->config->item('stash_cookie') : 'stashid';
        $this->stash_cookie_expire  = $this->EE->config->item('stash_cookie_expire') ? $this->EE->config->item('stash_cookie_expire') : 0;
        $this->default_scope        = $this->EE->config->item('stash_default_scope') ? $this->EE->config->item('stash_default_scope') : 'user';
        $this->limit_bots           = $this->EE->config->item('stash_limit_bots')    ? $this->EE->config->item('stash_limit_bots') : FALSE;

        // permitted file extensions for Stash embeds
        $this->file_extensions  =   $this->EE->config->item('stash_file_extensions')     
                                    ? $this->EE->config->item('stash_file_extensions') 
                                    : array('html', 'md', 'css', 'js', 'rss', 'xml');
        
        // initialise tag parameters
        if (FALSE === $calling_from_hook)
        {
            $this->init();
        }

        // fetch the stash session id
        if ( ! isset($this->EE->session->cache['stash']['_session_id']) )
        {   
            // do we have a stash cookie? 
            if ($cookie_data = $this->_get_stash_cookie())
            {
                // YES - restore session
                $this->EE->session->cache['stash']['_session_id'] = $cookie_data['id'];
                $last_activity = $cookie_data['dt'];
                
                if ( $last_activity + 300 < $this->EE->localize->now)
                {           
                    // refresh cookie
                    $this->_set_stash_cookie($cookie_data['id']);
                
                    // prune variables with expiry date older than right now 
                    $this->EE->stash_model->prune_keys();   
                }
            }
            else
            {
                if ($this->limit_bots)
                {
                    // Is the user a human? Legitimate bots don't set cookies so will end up here every page load
                    // Humans who accept cookies only get checked when the cookie is first set
                    self::$_is_human = ($this->_is_bot() ? FALSE : TRUE);
                }
                
                // NO - let's generate a unique id
                $unique_id = $this->EE->functions->random();
                
                // add to stash array
                $this->EE->session->cache['stash']['_session_id'] = $unique_id;
                
                // create a cookie; store the creation date in the cookie itself
                $this->_set_stash_cookie($unique_id);
            }
        }
        
        // create a reference to the session id
        $this->_session_id =& $this->EE->session->cache['stash']['_session_id'];
    }
    
    // ---------------------------------------------------------
    
    /**
     * Initialise tag parameters
     *
     * @access public
     * @param  bool      $calling_from_hook Is method being called by an extension hook?
     * @return void 
     */
    public function init($calling_from_hook = FALSE)
    {   
        // make sure we have a Template object to work with, in case Stash is being invoked outside of a template
        if ( ! class_exists('EE_Template'))
        {
            $this->_load_EE_TMPL();
        }
        
        // initialise internal flags
        $this->parse_complete = FALSE;
        $this->_update = FALSE;
        $this->_append = TRUE;
        $this->_embed_nested = FALSE;
        $this->process = 'inline';
        
        // postpone the parsing of the called stash tag?
        if (FALSE === $calling_from_hook)
        {   
            /* process stage:
                start = called prior to template parsing in the current template
                inline = process as a normal tag within the natural parse order of the template
                end = called after all tag parsing has completed
            */
            $this->process  = $this->EE->TMPL->fetch_param('process', 'inline'); // start | inline | end
            $this->priority = $this->EE->TMPL->fetch_param('priority', '1'); // ensure a priority is set
        }
        
        // legacy: make 'final' the same as 'end'
        if ($this->process == "final") 
        {
            $this->process = "end";
        }
        
        // tags can't be processed on start, only stash embeds
        if ($this->process == "start") 
        {
            $this->process = "inline";
        }
        
        // allow the site_id to be overridden, for e.g. shared variables across mutliple sites
        $this->site_id = (integer) $this->EE->TMPL->fetch_param('site_id', $this->site_id);

        // selected bundle
        $bundle = $this->EE->TMPL->fetch_param('bundle', 'default');

        // lookup the id of an existing bundle, or map to one of the preset bundles
        if ( ! $this->bundle_id = $this->EE->stash_model->get_bundle_by_name($bundle))
        {
            // not found, fallback to the default
            $this->bundle_id = 1;
        }
        
        // xss scripting protection
        $this->xss_clean = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('xss_clean'));
        
        // if the variable is already set, do we want to replace it's value? Default = yes
        $this->replace = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('replace', 'yes'));

        // parse="yes"?
        $this->set_parse_params();
        
        // do we want to parse any tags and variables inside tagdata? Default = no  
        $this->parse_tags = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('parse_tags'));
        $this->parse_vars = $this->EE->TMPL->fetch_param('parse_vars', NULL);
        
        // legacy behaviour: if parse_vars is null but parse tags is true, we should make sure vars are parsed too
        if ($this->parse_tags && $this->parse_vars == NULL)
        {
            $this->parse_vars = TRUE;
        }
        else
        {
            $this->parse_vars = (bool) preg_match('/1|on|yes|y/i', $this->parse_vars);
        }
        
        // parsing: how many passes of the template should we make? (more passes = more overhead). Default = 1
        $this->parse_depth = preg_replace('/[^0-9]/', '', $this->EE->TMPL->fetch_param('parse_depth', 1));
        
        // parsing: parse advanced conditionals. Default = no
        $this->parse_conditionals = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('parse_conditionals'));
        
        // stash type, default to 'variable'
        $this->type = strtolower( $this->EE->TMPL->fetch_param('type', 'variable') );
        
        // create a stash array in the session if we don't have one
        if ( ! array_key_exists('stash', $this->EE->session->cache) )
        {
            $this->EE->session->cache['stash'] = array();
        }   
        
        // determine the memory storage location
        if ($this->type === 'variable')
        {
            // we're setting/getting a 'native' stash variable
            $this->_stash =& $this->EE->session->cache['stash'];
        }
        elseif ($this->type === 'snippet' || $this->type === 'global')
        {
            // we're setting/getting a global variable {snippet}
            $this->_stash =& $this->EE->config->_global_vars;
        }
        else
        {
            $this->EE->output->show_user_error('general', $this->EE->lang->line('unknown_stash_type') . $this->type);
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Load the EE Template class and register the Stash module
     * Used when Stash is instantiated outside of an EE template
     *
     * @access private
     * @return void 
     */
    private function _load_EE_TMPL()
    {
        // -------------------------------------
        // 'stash_load_template_class' hook
        // -------------------------------------
        if ($this->EE->extensions->active_hook('stash_load_template_class') === TRUE)
        {
            $this->EE->TMPL = $this->EE->extensions->call('stash_load_template_class');
        } 
        else 
        {
            require_once APPPATH.'libraries/Template.php';
            $this->EE->TMPL = new EE_Template();
            $this->EE->TMPL->modules = array('stash');
        }
    }
    
    /*
    ================================================================
    Template tags
    ================================================================
    */
    
    /**
     * Shortcut to stash:get or stash:set
     * 
     * @param string     $name The method name being called or context if third tagpart
     * @param array      $arguments The method call arguments
     * 
     * @return void
     */
    public function __call($name, $arguments)
    {   
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:foo}
        
        is equivalent to:
        
        {exp:stash:get name="foo"}
        ---------------------------------------------------------
        {exp:stash:foo}
        CONTENT
        {/exp:stash:foo}
        
        is equivalent to:
        
        {exp:stash:set name="foo"}
        CONTENT
        {/exp:stash:set}
        ---------------------------------------------------------
        {exp:stash:bar:foo}
        
        is equivalent to:
    
        {exp:stash:get name="bar:foo"}
        and
        {exp:stash:get context="bar" name="foo"}
        ---------------------------------------------------------
        {exp:stash:bar:foo}
        CONTENT
        {/exp:stash:bar:foo}
        
        is equivalent to:
        {exp:stash:set context="bar" name="foo"}
        CONTENT
        {/exp:stash:set}
        and
        {exp:stash:set name="bar:foo"}
        CONTENT
        {/exp:stash:set}
        --------------------------------------------------------- */
        
        switch($name)
        {
            case 'unset' :
                // make 'unset' - a reserved word - an alias of destroy()
                return call_user_func_array(array($this, 'destroy'), $arguments);
            break;

            case 'static' :
                // make 'static' - a reserved word - an alias of static_cache()
                return call_user_func_array(array($this, 'static_cache'), $arguments);
            break;

            default :
            
            // if there is an extra tagpart, then we have a context and a name
            if (isset($this->EE->TMPL->tagparts[2]))
            {   
                $this->EE->TMPL->tagparams['context'] = $name;
                $this->EE->TMPL->tagparams['name'] = $this->EE->TMPL->tagparts[2];  
            }
            else
            {
                $this->EE->TMPL->tagparams['name'] = $name;
            }
            return $this->EE->TMPL->tagdata ? $this->set() : $this->get();
        }
    }

    
    /**
     * Set content in the current session, optionally save to the database
     *
     * @access public
     * @param  mixed     $params The name of the variable to retrieve, or an array of key => value pairs
     * @param  string    $value The value of the variable
     * @param  string    $type  The type of variable
     * @param  string    $scope The scope of the variable
     * @return void 
     */
    public function set($params=array(), $value='', $type='variable', $scope='user')
    {   
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:set name="title" type="snippet"}A title{/exp:stash:set}
        
        OR static call within PHP enabled templates or other add-on: 
        <?php stash::set('title', 'My title') ?>
        --------------------------------------------------------- */
        
        // is this method being called statically?
        if ( func_num_args() > 0 && !(isset($this) && get_class($this) == __CLASS__))
        {   
            return self::_static_call(__FUNCTION__, $params, $type, $scope, $value);
        }
        
        // do we want to set the variable?
        $set = TRUE;
        
        // var name
        $name = $this->EE->TMPL->fetch_param('name', FALSE);        
        
        // context handling
        $context = $this->EE->TMPL->fetch_param('context', NULL);
        
        if ( !! $name)
        {
            if ($context !== NULL && count( explode(':', $name) == 1 ) )
            {
                $name = $context . ':' . $name;
                $this->EE->TMPL->tagparams['context'] = NULL;
            }
        }
        
        // replace '@' placeholders with the current context
        $stash_key = $this->_parse_context($name);
        
        // scope
        $scope  = strtolower($this->EE->TMPL->fetch_param('scope', $this->default_scope)); // local|user|site
        
        // do we want this tag to return it's tagdata? (default: no)
        $output = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('output'));
        
        // append or prepend passed as parameters?
        if (preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('prepend')))
        {
            $this->_update = TRUE;
            $this->_append = FALSE;
        }
        elseif (preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('append')))
        {
            $this->_update = TRUE;
            $this->_append = TRUE;
        }
        
        // do we want to save this variable in a bundle?
        $bundle = $this->EE->TMPL->fetch_param('bundle', NULL); // save in a bundle?
        
        // do we want to replace an existing variable?
        if ( !! $name && ! $this->replace && ! $this->_update)
        {
            // try to get existing value
            $existing_value = FALSE;
            
            if ( array_key_exists($stash_key, $this->_stash))
            {
                $existing_value = $this->_stash[$name];
            }
            elseif ($scope !== 'local')
            {
                // narrow the scope to user?
                $session_id = $scope === 'user' ? $this->_session_id : '_global';
                
                $existing_value = $this->EE->stash_model->get_key(
                    $stash_key, 
                    $this->bundle_id,
                    $session_id, 
                    $this->site_id
                );
            }

            if ( $existing_value !== FALSE)
            {
                // yes, it's already been stashed
                $this->EE->TMPL->tagdata = $this->_stash[$name] = $existing_value;
                
                // don't overwrite existing value
                $set = FALSE;
            }
            unset($existing_value);
        }
        
        // do we want to ignore empty tagdata values?
        if ( $not_empty = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('not_empty')) )
        {
            if ( ! $this->not_empty())
            {
                $set = FALSE;
            }
        }
        
        if ($set)
        {
            // Check for a no_results prefix to avoid no_results parse conflicts
            if($no_results_prefix = $this->EE->TMPL->fetch_param('no_results_prefix'))
            {
                $this->EE->TMPL->tagdata = str_replace($no_results_prefix.'no_results', 'no_results', $this->EE->TMPL->tagdata);
                $this->EE->TMPL->tagdata = str_replace($no_results_prefix.':no_results', 'no_results', $this->EE->TMPL->tagdata);
            }
            
            if ( ($this->parse_tags || $this->parse_vars || $this->parse_conditionals) && ! $this->parse_complete)
            {   
                $this->_parse_sub_template($this->parse_tags, $this->parse_vars, $this->parse_conditionals, $this->parse_depth);
                $this->parse_complete = TRUE; // don't run again
            }
            
            // apply any string manipulations
            $this->EE->TMPL->tagdata = $this->_clean_string($this->EE->TMPL->tagdata);

            if ( !! $name )
            {                   
                // get params
                $label           = $this->EE->TMPL->fetch_param('label', $name);
                $save            = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('save'));                        
                $refresh         = (int) $this->EE->TMPL->fetch_param('refresh', 1440); // minutes (1440 = 1 day) 
                $match           = $this->EE->TMPL->fetch_param('match', NULL); // regular expression to test value against
                $against         = $this->EE->TMPL->fetch_param('against', $this->EE->TMPL->tagdata); // text to apply test against
                $filter          = $this->EE->TMPL->fetch_param('filter', NULL); // regex pattern to search for
                $default         = $this->EE->TMPL->fetch_param('default', NULL); // default value
                $delimiter       = $this->EE->TMPL->fetch_param('delimiter', '|'); // implode arrays using this delimiter
                
                // do we want to set a placeholder somewhere in this template ?
                $set_placeholder = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('set_placeholder'));
                
                // make sure we have a value to fallback to for output in current template
                if ($set_placeholder && is_null($default))
                {
                    $default = '';
                }

                // set refresh
                if ($refresh > 0)
                {
                    $refresh = $this->EE->localize->now + ($refresh * 60);
                }
                
                // regex match
                if ( $match !== NULL && preg_match('/^#(.*)#$/', $match))
                {   
                    $is_match = $this->_matches($match, $against);
                    
                    // did it fail to match the filter?
                    if ( ! $is_match )
                    {
                        // if a default has been specified fallback to it
                        if (! is_null($default))
                        {
                            $this->EE->TMPL->tagdata = $default;
                        }
                        else
                        {
                            return;
                        }
                    } 
                }               

                // regex filter
                if ( $filter !== NULL && ! is_array($this->EE->TMPL->tagdata))
                {
                    preg_match($filter, $this->EE->TMPL->tagdata, $found);
                    if (isset($found[1]))
                    {
                        $this->EE->TMPL->tagdata = $found[1];
                    }   
                }
                
                // make sure we're working with a string
                // if we're setting a variable from a global ($_POST, $_GET etc), it could be an array
                if ( is_array($this->EE->TMPL->tagdata))
                {   
                    $this->EE->TMPL->tagdata = array_filter($this->EE->TMPL->tagdata, 'strlen');
                    $this->EE->TMPL->tagdata = implode($delimiter, $this->EE->TMPL->tagdata);
                }
            
                if ( $this->_update )
                {
                    // We're updating a variable, so lets see if it's in the session or db
                    if ( ! array_key_exists($name, $this->_stash))
                    {
                        $this->_stash[$name] = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));
                    }
            
                    // Append or prepend?
                    if ( $this->_append )
                    {
                        $this->_stash[$name] .= $this->EE->TMPL->tagdata;
                    }
                    else
                    {
                        $this->_stash[$name] = $this->EE->TMPL->tagdata.$this->_stash[$name];
                    }
                } 
                else
                {
                    $this->_stash[$name] = $this->EE->TMPL->tagdata;
                }
                
                // replace value into a {placeholder} anywhere in the current template?
                if ($set_placeholder)
                {   
                    $this->EE->TMPL->template = $this->EE->functions->var_swap(
                        $this->EE->TMPL->template, 
                        array($name => $this->_stash[$name])
                    );
                }
                
                // allow user- and site- scoped variables to be saved to the db
                // stop bots saving data to reduce unnecessary load on the server
                if ($save && $scope !== 'local' && self::$_is_human)
                {   
                    // optionally clean data before inserting
                    $parameters = $this->_stash[$name];
                
                    if ($this->xss_clean)
                    {   
                        $this->EE->security->xss_clean($parameters);
                    }

                    // what's the intended variable scope? 
                    if ($scope === 'site')
                    {
                        $session_filter = '_global';
                    }
                    else
                    {
                        $session_filter =& $this->_session_id;
                    }
                    
                    // let's check if there is an existing record, and that that it matches the new one exactly
                    $result = $this->EE->stash_model->get_key($stash_key, $this->bundle_id, $session_filter, $this->site_id);

                    if ( $result !== FALSE)
                    {
                        // record exists, but is it identical?
                        // allow append/prepend if the stash key has been created *in this page load*
                        $cache_key = $stash_key. '_'. $this->bundle_id .'_' .$this->site_id . '_' . $session_filter;
                        
                        if ( $result !== $parameters && ($this->replace || ($this->_update && $this->EE->stash_model->is_inserted_key($cache_key)) ) )
                        {   
                            // nope - update
                            $this->EE->stash_model->update_key(
                                $stash_key,
                                $this->bundle_id,
                                $session_filter,
                                $this->site_id,
                                $refresh,
                                $parameters
                            );
                        }
                    }
                    else
                    {   
                        // no record - insert one
                        $this->EE->stash_model->insert_key(
                            $stash_key,
                            $this->bundle_id,
                            $session_filter,
                            $this->site_id,
                            $refresh,
                            $parameters,
                            $label
                        );
                    }
                }
            }
            else
            {
                // no name supplied, so let's assume we want to set sections of content within tag pairs
                // {stash:my_variable}...{/stash:my_variable}
                $vars = array();
                $tagdata = $this->EE->TMPL->tagdata;
            
                // context handling
                if ( $context !== NULL ) 
                {
                    $prefix = $context . ':';
                    $this->EE->TMPL->tagparams['context'] = NULL;
                }
                else
                {
                    $prefix = '';
                }
                
                // if the tagdata has been parsed, we need to generate a new array of tag pairs
                // this permits dynamic tag pairs, e.g. {stash:{key}}{/stash:{key}} 
                if ($this->parse_complete)
                {
                    $tag_vars = $this->EE->functions->assign_variables($this->EE->TMPL->tagdata);
                    $tag_pairs = $tag_vars['var_pair'];
                }
                else
                {
                    $tag_pairs =& $this->EE->TMPL->var_pair;
                }
            
                foreach($tag_pairs as $key => $val)
                {
                    if (strncmp($key, 'stash:', 6) ==  0)
                    {
                        $pattern = '/'.LD.$key.RD.'(.*)'.LD.'\/'.$key.RD.'/Usi';
                        preg_match($pattern, $tagdata, $matches);
                        if (!empty($matches))
                        {       
                            // set the variable, but cleanup first in case there are any nested tags
                            $this->EE->TMPL->tagparams['name'] = $prefix . str_replace('stash:', '', $key);
                            $this->EE->TMPL->tagdata = preg_replace('/'.LD.'stash:[a-zA-Z0-9\-_]+'.RD.'(.*)'.LD.'\/stash:[a-zA-Z0-9\-_]+'.RD.'/Usi', '', $matches[1]);
                            $this->parse_complete = TRUE; // don't allow tagdata to be parsed
                            $this->set();
                        }   
                    }
                }
            
                // reset tagdata to original value
                $this->EE->TMPL->tagdata = $tagdata;
                unset($tagdata);
            }
        }
        
        if ( !! $name)
        {
            if ( $bundle !== NULL)
            {
                if ( ! isset(self::$bundles[$bundle]))
                {
                    self::$bundles[$bundle] = array();
                }
                self::$bundles[$bundle][$name] = $this->_stash[$name];
            }

            $this->EE->TMPL->log_item('Stash: SET '. $name . ' to value ' . $this->_stash[$name]);  
        }
        
        if ($output)
        {
            return $this->EE->TMPL->tagdata;
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Get content from session, database, $_POST/$_GET superglobals or file
     *
     * @access public
     * @param  mixed     $params The name of the variable to retrieve, or an array of key => value pairs
     * @param  string    $type  The type of variable
     * @param  string    $scope The scope of the variable
     * @return string 
     */
    public function get($params='', $type='variable', $scope='user')
    {               
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:get name="title"}
        
        OR static call within PHP enabled templates or other add-on: 
        <?php echo stash::get('title') ?>
        --------------------------------------------------------- */

        // is this method being called statically?
        if ( func_num_args() > 0 && !(isset($this) && get_class($this) == __CLASS__))
        {   
            return self::_static_call(__FUNCTION__, $params, $type, $scope);
        }
        
        if ( $this->process !== 'inline') 
        {
            if ($out = $this->_post_parse(__FUNCTION__)) return $out;
        }

        $name           = $this->EE->TMPL->fetch_param('name');
        $default        = $this->EE->TMPL->fetch_param('default', NULL); // default value
        $dynamic        = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('dynamic'));
        $save           = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('save'));     
        $scope          = strtolower($this->EE->TMPL->fetch_param('scope', $this->default_scope)); // local|user|site
        $bundle         = $this->EE->TMPL->fetch_param('bundle', NULL); // save in a bundle?
        $match          = $this->EE->TMPL->fetch_param('match', NULL); // regular expression to test value against
        $filter         = $this->EE->TMPL->fetch_param('filter', NULL); // regex pattern to search for

        // do we want this tag to return the value, or just set the variable quietly in the background?
        $output = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('output', 'yes'));
        
        // parse any vars in the $name parameter?
        if ($this->parse_vars)
        {
            $name = $this->_parse_template_vars($name);
        }
        
        // low search support - do we have a query string?
        $low_query = $this->EE->TMPL->fetch_param('low_query', NULL);

        // context handling
        $context = $this->EE->TMPL->fetch_param('context', NULL);
        $global_name = $name;
        
        if ($context !== NULL && count( explode(':', $name) == 1 ) )
        {
            $name = $context . ':' . $name;
            $this->EE->TMPL->tagparams['context'] = NULL;
        }
        
        // parse '@' context pointers
        $name_in_context = $this->_parse_context($name);
        
        // read from file?
        $file = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('file'));
        $file_name = $this->EE->TMPL->fetch_param('file_name', FALSE); // default value
        
        // when to parse the variable if reading from a file and saving: 
        // before we save it to database (set) or when we retrieve it (get), or on set and get (both)
        $parse_stage = strtolower($this->EE->TMPL->fetch_param('parse_stage', 'set')); // set|get|both
        
        if ( !! $file_name)
        {
            $file = TRUE;
        }
        else
        {
            $file_name = $name;
        }
        
        // the variable value
        $value = NULL;
        
        // do we want to set the variable?
        $set = FALSE;
        
        // is it a segment? We need to support these in stash template files
        if (strncmp($name, 'segment_', 8) == 0)
        {
            $seg_index = substr($name, 8);
            $value = $this->EE->uri->segment($seg_index);
        }

        // let's see if it's been stashed before in this page load
        elseif ( array_key_exists($name, $this->_stash))
        {
            $value = $this->_stash[$name];          
        }
        
        // let's see if it exists in the current context
        elseif ( array_key_exists($name_in_context, $this->_stash))
        {
            $value = $this->_stash[$name_in_context];
            $name = $name_in_context;       
        }
        
        // not found in memory
        else
        {
            // has it been bundled?
            if ( ! is_null($bundle) && isset(self::$bundles[$bundle][$name]))
            {
                $value = $this->_stash[$name] = self::$bundles[$bundle][$name];
            }
            elseif ( ! $this->_update && ! ($dynamic && ! $save)  && $scope !== 'local')
            {
                // let's look in the database table cache, but only if if we're not
                // appending/prepending or trying to register a global without saving it
                
                // narrow the scope to user?
                $session_id = $scope === 'user' ? $this->_session_id : '_global';
            
                // replace '@' placeholders with the current context
                $stash_key = $this->_parse_context($name);
                    
                // look for our key
                if ( $parameters = $this->EE->stash_model->get_key(
                    $stash_key, 
                    $this->bundle_id,
                    $session_id, 
                    $this->site_id
                ))
                {   
                    // save to session 
                    $value = $this->_stash[$name] = $parameters;
                }
            }

            // Are we looking for a superglobal or uri segment?
            if ( ($dynamic && $value === NULL) || ($dynamic && $this->replace) )
            {   
                $from_global = FALSE;
                    
                // low search support
                if ($low_query !== NULL)
                {
                    // has the query string been supplied or is it in POST?
                    if (strncmp($low_query, 'stash:', 6) == 0)
                    {
                        $low_query = substr($low_query, 6);
                        $low_query = $this->_stash[$low_query];
                    }

                    $low_query = @unserialize(base64_decode(str_replace('_', '/', $low_query)));

                    if (isset( $low_query[$global_name] ))
                    {
                        $from_global = $low_query[$global_name];
                        unset($low_query);
                    }
                    else
                    {
                        // set to empty value
                        $from_global = '';
                    }
                }
                
                // or is it in the $_POST or $_GET superglobals ( run through xss_clean() )?
                if ($from_global === FALSE)
                {
                    $from_global = $this->EE->input->get_post($global_name, TRUE);
                }
                
                if ($from_global === FALSE)
                {
                    // no, so let's check the uri segments
                    $segs = $this->EE->uri->segment_array();

                    foreach ( $segs as $index => $segment )
                    {
                        if ( $segment == $global_name && array_key_exists( ($index+1), $segs) )
                        {
                            $from_global = $segs[($index+1)];
                            break;
                        }
                    }
                }
                
                if ($from_global !== FALSE)
                {
                    // save to stash, and optionally to database, if save="yes"
                    $value = $from_global;
                    $set = TRUE;
                }
            }
            
            // Are we reading a file?
            if ( ($file && $value === NULL) || ($file && $this->replace) || ($file && $this->file_sync) )
            {                   
                $this->EE->TMPL->log_item("Stash: reading from file");

                // extract and remove the file extension, if provided
                $ext = 'html'; // default extension

                #  PHP 5.3+ only
                # $file_ext = preg_filter('/^.*\./', '', $file_name);   
                $file_ext = NULL;
                if ( preg_match('/^.*\./', $file_name) )
                {
                    $file_ext = preg_replace('/^.*\./', '', $file_name);
                }

                // make sure the extension is allowed
                if ( ! is_null($file_ext))
                {
                    if ( in_array($file_ext, $this->file_extensions))
                    {
                        $ext = $file_ext;
                    }
                }
                
                // strip file ext (if any) and make sure we have a safe url encoded file path
                $file_path = preg_replace('/\.[^.]*$/', '', $file_name);
                $file_path = explode(':', $file_path);

                foreach($file_path as &$part)
                {
                    // make sure it's a valid url title
                    $part = str_replace('.', '', $part);
                    
                    // make sure our url part is a valid 'slug'
                    if (function_exists('iconv'))
                    {
                        // swap out Non "Letters" with a hyphen -
                        $part = preg_replace('/[^\\pL\d\_]+/u', '-', $part); 

                        // trim out extra -'s
                        $part = trim($part, '-');

                        // convert letters that we have left to the closest ASCII representation
                        $part= iconv('utf-8', 'us-ascii//TRANSLIT', $part);

                         // strip out anything we haven't been able to convert
                        $part = preg_replace('/[^-\w]+/', '', $part);
                    }
                    else
                    {
                        // else insist upon alphanumeric characters and - or _
                        $part = trim(preg_replace('/[^a-z0-9\-\_]+/', '-', strtolower($part)), '-');
                    }

                }
                unset($part); // remove reference

                // remove any empty url parts
                $file_path = array_filter($file_path);
                
                $file_path = $this->path . implode('/', $file_path) . '.' . $ext;

                if ( file_exists($file_path))
                {               
                    $value = str_replace("\r\n", "\n", file_get_contents($file_path));

                    $set = TRUE;
                    
                    // disable tag parsing on set when parse_stage is 'get'
                    if ($parse_stage == 'get')
                    {
                        $this->parse_complete = TRUE;
                    }
                }
                else
                {
                    $this->EE->output->show_user_error('general', sprintf($this->EE->lang->line('stash_file_not_found'), $file_path));
                    return;
                }
            }
        }
        
        // set to default value if it NULL or empty string (this permits '0' to be a valid value)
        if ( ($value === NULL || $value === '') && ! is_null($default))
        {   
            $value = $default;
            $set = TRUE;    
        }
        
        // create/update value of variable if required
        // note: don't save if we're updating a variable (to avoid recursion)
        if ( $set && ! $this->_update)
        {   

            $this->EE->TMPL->tagparams['name'] = $name;
            $this->EE->TMPL->tagparams['output'] = 'yes';
            $this->EE->TMPL->tagdata = $value;
            $this->replace = TRUE;
            $value = $this->set();  
        }
            
        $this->EE->TMPL->log_item('Stash: RETRIEVED '. $name . ' with value ' . $value);
        
        // save to bundle
        if ($bundle !== NULL)
        {
            if ( ! isset(self::$bundles[$bundle]))
            {
                self::$bundles[$bundle] = array();
            }
            self::$bundles[$bundle][$name] = $value;
        }            
        
        // are we outputting the variable?
        if ($output)
        {
            if ( ! $file )
            {
                $value = $this->_parse_output($value, $match, $filter, $default);
            }
            else
            {
                // If this is a variable loaded originally from a file, parse if the desired parse stage is on retrieval (parse_stage="get|both")
                if ( ($parse_stage == 'get' || $parse_stage == 'both'))
                {
                    $this->parse_complete = FALSE; // enable parsing
                    $this->parse_vars = TRUE; // ensure early global and stash vars are always fully parsed
                    $value = $this->_parse_output($value, $match, $filter, $default);
                }
                else
                {
                    // ensure early global vars are always parsed
                    $value = $this->_parse_template_vars($value);
                }
            }
            return $value;
        }
    }

    // ---------------------------------------------------------
    
    /**
     * Define default/fallback content for a stash variable from enclosed tagdata
     *
     * @access public
     * @return string 
     */
    public function block()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:block name="page_content"}
            default content
        {/exp:stash:block}
        --------------------------------------------------------- */
        $this->EE->TMPL->tagparams['default'] = $this->EE->TMPL->tagdata;
        $this->EE->TMPL->tagdata = FALSE;
        return $this->get();
    }


    // ---------------------------------------------------------
    
    /**
     * Clone a variable / list
     *
     * @access public
     * @return string 
     */
    public function copy()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:copy 
            name="original_name" 
            context="original_context" 
            scope="original_scope"
            type="original_type"
            copy_name="copy_name" 
            copy_context="copy_context"
            copy_scope="copy_scope"
            copy_type="copy_type"
        }
        --------------------------------------------------------- */

        // get the original variable value, restricting which params are passed to a minimum
        $this->EE->TMPL->tagdata = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));

        // prep the tagparams with the values for the clone
        $this->EE->TMPL->tagparams['name']      = $this->EE->TMPL->fetch_param('copy_name', FALSE);
        $this->EE->TMPL->tagparams['context']   = $this->EE->TMPL->fetch_param('copy_context', NULL);
        $this->EE->TMPL->tagparams['scope']     = strtolower($this->EE->TMPL->fetch_param('copy_scope', $this->default_scope));
        $this->EE->TMPL->tagparams['type']      = $this->EE->TMPL->fetch_param('copy_type', 'variable');

        // re-initialise Stash with the new params
        $this->init();
        
        // clone the bugger
        return $this->set();
    }
    
    // ---------------------------------------------------------
    
    /**
     * Append the specified value to an already existing variable.
     *
     * @access public
     * @return void 
     */
    public function append()
    {
        $this->_update = TRUE;
        $this->_append = TRUE;
        return $this->set();
    }
    
    // ---------------------------------------------------------
    
    /**
     * Prepend the specified value to an already existing variable.
     *
     * @access public
     * @return void 
     */
    public function prepend()
    {
        $this->_update = TRUE;
        $this->_append = FALSE;
        return $this->set();
    }
        
    // ---------------------------------------------------------
    
    /**
     * Single tag version of set(), for when you need to use a 
     * plugin as a tag parameter (always use with parse="inward")
     * 
     *
     * @access public
     * @param bool   $update Update an existing stashed variable
     * @return void 
     */
    public function set_value()
    {   
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:set_value name="title" value="{exp:another:tag}" type="snippet" parse="inward"}
        --------------------------------------------------------- */
        
        $this->EE->TMPL->tagdata = $this->EE->TMPL->fetch_param('value', FALSE);
        
        if ( $this->EE->TMPL->tagdata !== FALSE )
        {
            return $this->set();
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Single tag version of append()
     *
     * @access public
     * @return void 
     */
    public function append_value()
    {
        $this->_update = TRUE;
        $this->_append = TRUE;
        return $this->set_value();
    }
    
    // ---------------------------------------------------------
    
    /**
     * Single tag version of prepend()
     *
     * @access public
     * @return void 
     */
    public function prepend_value()
    {
        $this->_update = TRUE;
        $this->_append = FALSE;
        return $this->set_value();
    }
    
    // ---------------------------------------------------------
    
    /**
     * Set the current context
     *
     * @access public
     * @return void
     */
    public function context()
    {
        if ( !! $name = $this->EE->TMPL->fetch_param('name', FALSE) )
        {
            self::$context = $name;
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Checks if a variable or string is empty or non-existent, handy for conditionals
     *
     * @access public
     * @param $string a string to test
     * @return integer
     */
    public function not_empty($string = NULL)
    {
        /* Sample use
        ---------------------------------------------------------
        Check a native stash variable, global variable or snippet is not empty:
        {if {exp:stash:not_empty type="snippet" name="title"} }
            Yes! {title} is not empty
        {/if}
        
        Check any string or variable is not empty even if it's not been Stashed:
        {if {exp:stash:not_empty:string}{my_string}{/exp:stash:not_empty:string} }
            Yes! {my_string} is not empty
        {/if}
        --------------------------------------------------------- */
        if ( ! is_null($string))
        {
            $test = $string;
        }
        elseif ( $this->EE->TMPL->tagdata )
        {
            // parse any vars in the string we're testing
            $this->_parse_sub_template(FALSE, TRUE);
            $test = $this->EE->TMPL->tagdata;
        }
        else
        {
            $test = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));
        }
        
        $value  = str_replace( array("\t", "\n", "\r", "\0", "\x0B"), '', trim($test));
        return empty( $value ) ? 0 : 1;
    }   

    // ---------------------------------------------------------

    /**
     * Checks if a variable or string has any content, handy for conditionals
     *
     * @access public
     * @param $string a string to test
     * @return integer
     */
    public function is_empty($string = NULL)
    {
        /* Sample use
        ---------------------------------------------------------
        Check a native stash variable, global variable or snippet is empty:
        {if {exp:stash:is_empty type="snippet" name="title"} }
            Yes! {title} is empty
        {/if}

        Check any string or variable is not empty even if it's not been Stashed:
        {if {exp:stash:is_empty:string}{my_string}{/exp:stash:is_empty:string} }
            Yes! {my_string} is empty
        {/if}
        --------------------------------------------------------- */
        return $this->not_empty($string) == 0 ? 1 : 0;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Serialize a multidimenisional array and save as a variable
     *
     * @access public
     * @return string 
     */
    public function set_list()
    {   
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:set_list name="blog_entries"}
            {stash:item_title}{title}{/stash:item_title}
            {stash:item_img_url}{img_url}{/stash:item_img_url}
            {stash:item_copy}{copy}{/stash:item_copy}
        {/exp:stash:set_list}
        --------------------------------------------------------- */
        
        // name and context
        $name = $this->EE->TMPL->fetch_param('name', FALSE);        
        $context = $this->EE->TMPL->fetch_param('context', NULL);
        $scope  = strtolower($this->EE->TMPL->fetch_param('scope', $this->default_scope)); // local|user|site
        
        if ( !! $name)
        {
            if ($context !== NULL && count( explode(':', $name) == 1 ) )
            {
                $name = $context . ':' . $name;
            }
        }
        
        // replace '@' placeholders with the current context
        $stash_key = $this->_parse_context($name);
        
        // no results prefix
        $prefix = $this->EE->TMPL->fetch_param('prefix', NULL);
        
        // check for prefixed no_results block
        if ( ! is_null($prefix))
        {
            $this->_prep_no_results($prefix);
        }
        
        // do we want to replace an existing list variable?
        $set = TRUE;

        if ( ! $this->replace && ! $this->_update)
        {   
            // try to get existing value
            $existing_value = FALSE;
            
            if ( array_key_exists($name, $this->_stash))
            {
                $existing_value = $this->_stash[$name];
            }
            elseif ($scope !== 'local')
            {
                // narrow the scope to user?
                $session_id = $scope === 'user' ? $this->_session_id : '_global';
                
                $existing_value = $this->EE->stash_model->get_key(
                    $stash_key, 
                    $this->bundle_id,
                    $session_id, 
                    $this->site_id
                );
            }

            if ( $existing_value !== FALSE)
            {   
                // yes, it's already been stashed, make sure it's in the stash memory cache
                $this->EE->TMPL->tagdata = $this->_stash[$name] = $existing_value;
                
                // don't overwrite existing value
                $set = FALSE;
            }
            unset($existing_value);
        }
        
        if ($set)
        {   
            // do any parsing and string transforms before making the list
            $this->EE->TMPL->tagdata = $this->_parse_output($this->EE->TMPL->tagdata);
        
            // regenerate tag variable pairs array using the parsed tagdata
            $tag_vars = $this->EE->functions->assign_variables($this->EE->TMPL->tagdata);
            $this->EE->TMPL->var_pair = $tag_vars['var_pair'];
        
            // get the first key and see if it repeats
            $keys = array_keys($this->EE->TMPL->var_pair);
        
            if ( ! empty($keys))
            {
                $first_key = $keys[0];

                preg_match_all('/'. LD . $first_key . RD . '/', $this->EE->TMPL->tagdata, $matches);
        
                if (count($matches[0]) > 1)
                {
                    // yes we have repeating keys, so let's split the tagdata up into rows
                    $this->EE->TMPL->tagdata = str_replace(
                            LD . $first_key . RD, 
                            $this->_list_delimiter . LD . $first_key . RD,
                            $this->EE->TMPL->tagdata
                    );
            
                    // get an array of rows, remove first element which will be empty
                    $rows = explode($this->_list_delimiter, $this->EE->TMPL->tagdata);
                    array_shift($rows);
            
                    // serialize each row and append
                    // bracket the serilaized string with delimiters
                    $tagdata = '';
                    foreach($rows as $row)
                    {
                        $this->EE->TMPL->tagdata = $row;
                        $this->_serialize_stash_tag_pairs();
                        if ( ! empty($this->EE->TMPL->tagdata))
                        {
                            $tagdata .= $this->_list_delimiter . $this->EE->TMPL->tagdata;
                        }
                    }
                    $this->EE->TMPL->tagdata = trim($tagdata, $this->_list_delimiter);
                }
                else
                {
                    //  get the stash var pairs values
                    $this->_serialize_stash_tag_pairs();
                }
        
                if ( $this->not_empty($this->EE->TMPL->tagdata))
                {
                    // set the list, but do we need to disable match/against?
                    if  ( FALSE !== $this->EE->TMPL->fetch_param('against', FALSE))
                    {
                        // already matched/against a specified column in the list, so disable match/against
                        unset($this->EE->TMPL->tagparams['match']);
                        unset($this->EE->TMPL->tagparams['against']);
                    }

                    return $this->set();    
                }
            }
            else
            {
                // make sure this variable is marked as empty, so subsquent get_list() calls return no_results
                $this->_stash[$name] = '';
                
                if ((bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('output'))) // default="no"
                { 
                    // optionally parse and return no_results tagdata
                    // note: output="yes" with set_list should only be used for debugging
                    return $this->_no_results(); 
                }
                else
                {   
                    // parse no_results tagdata, but don't output
                    // note: unless parse_tags="yes", no parsing would occur
                    $this->_no_results();
                }
            }
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Append to array
     *
     * @access public
     * @return string 
     */
    public function append_list()
    {   
        return $this->_update_list(TRUE); 
    }
    
    // ---------------------------------------------------------
    
    /**
     * Prepend to array
     *
     * @access public
     * @return string 
     */
    public function prepend_list()
    {   
        return $this->_update_list(FALSE); 
    }


    // ---------------------------------------------------------
    
    /**
     * Append / prepend to array
     *
     * @access private
     * @param  bool  $append Append or prepend to an existing list?
     * @return string 
     */
    private function _update_list($append=TRUE)
    {
        $name = $this->EE->TMPL->fetch_param('name');
        $context = $this->EE->TMPL->fetch_param('context', NULL);
        $this->EE->TMPL->tagdata = $this->_parse_output($this->EE->TMPL->tagdata);
        $this->_serialize_stash_tag_pairs();
        
        if ( $this->not_empty($this->EE->TMPL->tagdata))
        {
            // does the list really exist?
            if ($context !== NULL && count( explode(':', $name) == 1 ) )
            {
                $name = $context . ':' . $name;
            }
            $name_in_context = $this->_parse_context($name);

            // get the current value of the list
            $current_value = '';

            if (array_key_exists($name, $this->_stash))
            {
                $current_value = $this->_stash[$name];
            }
            elseif(array_key_exists($name_in_context, $this->_stash))
            {
                $current_value = $this->_stash[$name_in_context];
            }

            // check that the list has a value before appending/prepending
            if ( $this->not_empty($current_value))
            {
                if ($append)
                {
                    $this->EE->TMPL->tagdata = $this->_list_delimiter . $this->EE->TMPL->tagdata;
                }
                else
                {
                    $this->EE->TMPL->tagdata =  $this->EE->TMPL->tagdata . $this->_list_delimiter;
                }
            }

            // update the list, but do we need to disable match/against?
            if  ( FALSE !== $this->EE->TMPL->fetch_param('against', FALSE))
            {
                // already matched/against a specified column in the list, so disable match/against
                unset($this->EE->TMPL->tagparams['match']);
                unset($this->EE->TMPL->tagparams['against']);
            }

            return $append ? $this->append() : $this->prepend();
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Retrieve a serialised array of items, explode and replace into tagdata
     *
     * @access public
     * @return string 
     */
    public function get_list()
    {                           
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:get_list name="page_items" orderby="item_title" sort="asc"}
            <h2>{item_title}</h2>
            <img src="{item_img_url}" />
            {item_copy}
        {/exp:stash:get_list}
        --------------------------------------------------------- */    
        if ( $this->process !== 'inline') 
        {
            if ($out = $this->_post_parse(__FUNCTION__)) return $out;
        }

        $limit          = $this->EE->TMPL->fetch_param('limit',  FALSE);
        $offset         = $this->EE->TMPL->fetch_param('offset', 0);
        $default        = $this->EE->TMPL->fetch_param('default', ''); // default value
        $filter         = $this->EE->TMPL->fetch_param('filter', NULL); // regex pattern to search final output for
        $prefix         = $this->EE->TMPL->fetch_param('prefix', NULL); // optional namespace for common vars like {count}
        $paginate       = $this->EE->TMPL->fetch_param('paginate', FALSE);
        $paginate_param = $this->EE->TMPL->fetch_param('paginate_param', NULL); // if using query string style pagination
        $slice          = $this->EE->TMPL->fetch_param('slice', NULL); // e.g. "0, 2" - slice the list array before order/sort/limit
        
        $list_html      = '';
        $list_markers   = array();  
        
        // check for prefixed no_results block
        if ( ! is_null($prefix))
        {
            $this->_prep_no_results($prefix);
        }
                    
        // retrieve the list array
        $list = $this->rebuild_list();

        // return no results if this variable has no value
        if ( empty($list))
        {   
            return $this->_no_results();
        }
        
        // apply prefix
        if ( ! is_null($prefix))
        {
            foreach ($list as $index => $array)
            {
                foreach ($array as $k => $v)
                {
                    $list[$index][$prefix.':'.$k] = $v;
                     // do we want to stop un-prefixed palceholders being parsed?
                    #unset($list[$index][$k]);
                }
            }
        }

        // slice the list before we do any further transformations on the list?
        if ( ! is_null($slice))
        {
            $slice = array_map('intval', explode(',', $slice));
            if (isset($slice[1]))
            {
                $list = array_slice($list, $slice[0], $slice[1]);
            }
            else
            {
                $list = array_slice($list, $slice[0]);
            }
        }
        
        // absolute results total
        $absolute_results = count($list);

        // does limit contain a fraction?
        if ($limit)
        {   
            $limit = $this->_parse_fraction($limit, $offset, $absolute_results);
        }

        // does offset contain a fraction, e.g. '1/3' ?
        if ($offset)
        {
            $offset = $this->_parse_fraction($offset, 0, $absolute_results);
        }
        
        // pagination
        if ($paginate)
        {   
            // remove prefix if used in the paginate tag pair
            if ( ! is_null($prefix))
            {
                if (preg_match("/(".LD.$prefix.":paginate".RD.".+?".LD.'\/'.$prefix.":paginate".RD.")/s", $this->EE->TMPL->tagdata, $paginate_match))
                {
                    $paginate_template = str_replace($prefix.':','', $paginate_match[1]);
                    $this->EE->TMPL->tagdata = str_replace($paginate_match[1], $paginate_template, $this->EE->TMPL->tagdata);
                }
            }
                    
            // pagination template
            $this->EE->load->library('pagination');
            
            // are we passing the offset in the query string?
            if ( ! is_null($paginate_param))
            {
                // prep the base pagination object
                $this->EE->pagination->query_string_segment = $paginate_param;
                $this->EE->pagination->page_query_string = TRUE;
            }
            
            $this->pagination = new Pagination_object(__CLASS__);

            // pass the offset to the pagination object
            if ( ! is_null($paginate_param))
            {
                // we only want the offset integer, ignore the 'P' prefix inserted by EE_Pagination
                $this->pagination->offset = filter_var($this->EE->input->get($paginate_param, TRUE), FILTER_SANITIZE_NUMBER_INT);
                
                if ( ! is_null($this->EE->TMPL->fetch_param('paginate_base', NULL)))
                {
                    // make sure paginate_base ends with a '?', if specified
                    $this->EE->TMPL->tagparams['paginate_base'] = rtrim($this->EE->TMPL->tagparams['paginate_base'], '?') . '?';
                }
            }
            else
            {
                $this->pagination->offset = 0;
            }
            
            // build that mother
            $this->pagination->per_page   = $limit ? $limit : 100; // same default limit as channel entries module
            $this->pagination->total_rows = $absolute_results - $offset;
            $this->pagination->get_template();
            $this->pagination->build(); 
            $offset = $offset + $this->pagination->offset;
        }
        
        // {absolute_count} - absolute count to the ordered/sorted items
        $i=0;
        foreach($list as $key => &$value)
        {
            $i++;
            $value['absolute_count'] = $i;
            
            // {prefix:absolute_count}
            if ( ! is_null($prefix))
            {
                $value[$prefix.':absolute_count'] = $i;
            }
        }
        
        // {absolute_results} - record the total number of list rows
        $list_markers['absolute_results'] = $absolute_results;
        
        // slice array depending on limit/offset
        if ($limit && $offset)
        {
            $list = array_slice($list, $offset, $limit);
        }
        elseif ($limit)
        {
            $list = array_slice($list, 0, $limit);
        }
        elseif ($offset)
        {
            $list = array_slice($list, $offset);
        }
        
        // prefixes
        if ( ! is_null($prefix))
        {
            // {prefix:absolute_results}
            $list_markers[$prefix.':absolute_results'] = $list_markers['absolute_results'];
            
            // {prefix:total_results}
            $list_markers[$prefix.':total_results'] = count($list);
        }   
        
        if (count($list) > 0)
        {   
            if ( ! is_null($prefix))
            {
                // {prefix:count}
                $i=0;
                foreach($list as $key => &$v)
                {
                    $i++;
                    $v[$prefix.':count'] = $i;
                }
                
                // {prefix:switch = ""}
                if (strpos($this->EE->TMPL->tagdata, LD.$prefix.':switch') !== FALSE)
                {
                    $this->EE->TMPL->tagdata = str_replace(LD.$prefix.':switch', LD.'switch', $this->EE->TMPL->tagdata);
                }   
            }
            
            // disable backspace param to stop parse_variables() doing it automatically
            // because it can potentially break unparsed conditionals / tags etc in the list
            $backspace = $this->EE->TMPL->fetch_param('backspace', FALSE);
            $this->EE->TMPL->tagparams['backspace'] = FALSE;
            
            // replace into template        
            $list_html = $this->EE->TMPL->parse_variables($this->EE->TMPL->tagdata, $list);
        
            // restore original backspace parameter
            $this->EE->TMPL->tagparams['backspace'] = $backspace;
        
            // parse other markers
            $list_html = $this->EE->TMPL->parse_variables_row($list_html, $list_markers);
            
            // render pagination
            if ($paginate)
            {
                $list_html = $this->pagination->render($list_html);
            }
        
            // now apply final output transformations / parsing
            return $this->_parse_output($list_html, NULL, $filter, $default);
        }
        else
        {
            return $this->_no_results();
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Retrieve the item count for a given list
     *
     * @access public
     * @return string 
     */
    public function list_count()
    {
        $match          = $this->EE->TMPL->fetch_param('match', NULL); // regular expression to each list item against
        $against        = $this->EE->TMPL->fetch_param('against', NULL); // key to test $match against  
                
        // retrieve the list array
        $list = $this->rebuild_list();

        // return 0 if this variable has no value
        if ($list == '') return '0';

        $count = count($list);
        return "$count"; // make sure we return a string
    }
    
    // ---------------------------------------------------------
    
    /**
     * Restore values for a given bundle
     *
     * @access public
     * @param bool set the bundled variables into Stash variables?
     * @return string
     */
    public function get_bundle($set=TRUE)
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:get_bundle name="contact_form" context="@" limit="5"}
            {contact_name}
        {/exp:stash:get_bundle}
        --------------------------------------------------------- */
        $out = $this->EE->TMPL->tagdata;
        
        if ( !! $bundle = $this->EE->TMPL->fetch_param('name', FALSE) )
        {
            
            // get the bundle id, cache to memory for efficient reuse later
            $bundle_id = $this->EE->stash_model->get_bundle_by_name($bundle);
            
            // does this bundle already exist?
            if ( $bundle_id )
            {           
                $bundle_array = array();
                $tpl = $this->EE->TMPL->tagdata;
                $this->bundle_id = $bundle_id;
                
                // get params
                $unique = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('unique', 'yes'));
                $index  = $this->EE->TMPL->fetch_param('index', NULL);  
                $context = $this->EE->TMPL->fetch_param('context', NULL);
                $scope = strtolower($this->EE->TMPL->fetch_param('scope', 'user')); // user|site

                // if this is a unique bundle, restore the bundled variables to static bundles array
                if ($unique || ! is_null($index))
                {       
                    if ( $index !== NULL && $index > 0)
                    {
                        $bundle .= '_'.$index;
                        $this->EE->TMPL->tagparams['name'] = $bundle;
                    }
                    
                    // get bundle var
                    $bundle_entry_key = $bundle;
                    if ($bundle !== NULL && count( explode(':', $bundle) == 1 ) )
                    {
                        $bundle_entry_key = $context . ':' . $bundle;
                    }
                    $session_id = $scope === 'user' ? $this->_session_id : '';
                    $bundle_entry_key = $this->_parse_context($bundle_entry_key);
                    
                    // look for our key
                    if ( $bundle_value = $this->EE->stash_model->get_key(
                        $bundle_entry_key, 
                        $this->bundle_id,
                        $session_id, 
                        $this->site_id
                    ))
                    {   
                        $bundle_array[0] = unserialize($bundle_value);
                        
                        foreach ($bundle_array[0] as $key => $val)
                        {
                            self::$bundles[$bundle][$key] = $val;
                        }   
                    }   
                }
                else
                {
                    // FUTURE FEATURE: get all entries for a bundle with *multiple* rows    
                }
                
                // replace into template
                if ( ! empty($tpl))
                {
                    // take care of any unparsed current context pointers '@'
                    if ( ! is_null($context))
                    {
                        $tpl = str_replace(LD.'@:', LD.$context.':', $tpl);
                    }
                    
                    if ( ! empty($bundle_array))
                    {
                        $out = '';
                        foreach($bundle_array as $vars)
                        {   
                            $out .= $this->EE->functions->var_swap($tpl, $vars);
                        
                            // set variables
                            if ($set)
                            {
                                foreach($vars as $name => $value)
                                {
                                    $this->EE->TMPL->tagparams['name'] = $name;
                                    $this->EE->TMPL->tagparams['type'] = 'variable';
                                    $this->EE->TMPL->tagdata = $value;
                                    $this->replace = TRUE;
                                
                                    $this->_run_tag('set', array('name', 'type', 'scope', 'context'));
                                }
                            }
                        }
                    }
                    
                    // prep 'IN' conditionals if the retreived var is a delimited string
                    $out = $this->_prep_in_conditionals($out);
                }
                
                $this->EE->TMPL->log_item("Stash: RETRIEVED bundle ".$bundle);
            }
        }
            
        return $out;    
    }
    
    // ---------------------------------------------------------
    
    /**
     * Set values into a bundle
     *
     * @access public
     * @return void 
     */
    public function set_bundle()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:set_bundle name="contact_form"}
        --------------------------------------------------------- */
        
        if ( !! $bundle = $this->EE->TMPL->fetch_param('name', FALSE) )
        {           
            if ( isset(self::$bundles[$bundle]))
            {
                // get params
                $bundle_label = $this->EE->TMPL->fetch_param('label', $bundle);
                $unique = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('unique', 'yes'));
                $bundle_entry_key = $bundle_entry_label = $bundle;
                
                // get the bundle id
                $bundle_id = $this->EE->stash_model->get_bundle_by_name($bundle);
                
                // does this bundle already exist? Let's try to get it's id
                if ( ! $bundle_id )
                {
                    // doesn't exist, let's create it
                    $bundle_id = $this->EE->stash_model->insert_bundle(
                        $bundle,
                        $bundle_label
                    );      
                }
                elseif ( ! $unique)
                {
                    // bundle exists, but do we want more than one entry per bundle?
                    $entry_count = $this->EE->stash_model->bundle_entry_count($bundle_id, $this->site_id);
                    if ($entry_count > 0)
                    {
                        $bundle_entry_key .= '_'.$entry_count;
                        $bundle_entry_label = $bundle_entry_key;
                    }
                }
                
                // stash the data under a single key
                $this->EE->TMPL->tagparams['name']  = $bundle_entry_key;
                $this->EE->TMPL->tagparams['label'] = $bundle_entry_label;
                $this->EE->TMPL->tagparams['save']  = 'yes';
                $this->EE->TMPL->tagparams['scope'] = 'user';
                $this->EE->TMPL->tagdata = serialize(self::$bundles[$bundle]);
                $this->bundle_id = $bundle_id;
                
                unset(self::$bundles[$bundle]);
                return $this->set();    
            }
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Bundle up a collection of variables and save in the database
     *
     * @access public
     * @param array $params 
     * @param array $dynamic 
     * @return void 
     */
    public function bundle($params = array(), $dynamic = array())
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:bundle name="contact_form" context="@" unique="no" type="snippet" refresh="10"}
            {exp:stash:get dynamic="yes" name="orderby" output="no" default="persons_last_name" match="#^[a-zA-Z0-9_-]+$#"}
            {exp:stash:get dynamic="yes" name="sort" output="no" default="asc" match="#^asc$|^desc$#"}
            {exp:stash:get dynamic="yes" name="filter" output="no" default="" match="#^[a-zA-Z0-9_-]+$#"}
            {exp:stash:get dynamic="yes" name="in" output="no" default="" match="#^[a-zA-Z0-9_-]+$#"}
            {exp:stash:get dynamic="yes" name="field" output="no" match="#^[a-zA-Z0-9_-]+$#" default="persons_last_name"}
        {/exp:stash:bundle}
        --------------------------------------------------------- */
        
        // is this method being called statically from PHP?
        if ( func_num_args() > 0 && !(isset($this) && get_class($this) == __CLASS__))
        {
            // as this function is called statically, 
            // we need to get an instance of this object and run get()
            $self = new self(); 
            
            // set parameters
            $this->EE->TMPL->tagparams = $params;
            
            // convert tagdata array
            if ( is_array($dynamic))
            {
                $this->EE->TMPL->tagdata = '';
                
                foreach ($dynamic as $name => $options)
                {
                    $this->EE->TMPL->tagdata .= LD.'exp:stash:get dynamic="yes" name="'.$name.'"';
                    foreach ($options as $option => $value)
                    {
                        $this->EE->TMPL->tagdata .= ' '.$option.'="'.$value.'"';
                    }
                    $this->EE->TMPL->tagdata .= RD;
                }
            }
            else
            {
                $this->EE->TMPL->tagdata = $dynamic;
            }
        
            return $self->bundle();
        }
        
        if ( !! $bundle = $this->EE->TMPL->fetch_param('name', FALSE) )
        {
            // build a string of parameters to inject into nested stash tags
            $context = $this->EE->TMPL->fetch_param('context', NULL);
            $params = 'bundle="' . $bundle . '" scope="local"';
            
            if ($context !== NULL )
            {
                $params .=  ' context="'.$context.'"';
            }
            
            // add params to nested tags
            $this->EE->TMPL->tagdata = preg_replace( '/('.LD.'exp:stash:get|'.LD.'exp:stash:set)/i', '$1 '.$params, $this->EE->TMPL->tagdata);
            
            // get existing values from bundle
            $this->get_bundle(FALSE);
            
            // parse stash tags in the bundle
            $this->_parse_sub_template();
            
            // save the bundle values
            $this->set_bundle();
        }
    }
    
    // ----------------------------------------------------------

    /**
     * Embed a Stash template file in the current template
     *
     * @access public
     * @return string 
     */
    public function embed()
    {   
        /* Sample use
        ---------------------------------------------------------
        {stash:embed name="my_template" 
            context="my_template_folder" 
            process="start"
            stash:another_var1="value 1"
            stash:another_var2="value 2"
        }
        
        Alternative sytax:
        {stash:embed:name} or
        {stash:embed:context:name}
        --------------------------------------------------------- */
            
        // mandatory parameter values for template files
        $this->EE->TMPL->tagparams['scope']               = 'site';
        $this->EE->TMPL->tagparams['file']                = 'yes';
        $this->EE->TMPL->tagparams['save']                = 'yes';
        $this->EE->TMPL->tagparams['embed_vars']          = array();

        // parse="yes"?
        $this->set_parse_params();

        // default parameter values
        $this->EE->TMPL->tagparams['parse_tags']          = $this->EE->TMPL->fetch_param('parse_tags', 'yes');
        $this->EE->TMPL->tagparams['parse_vars']          = $this->EE->TMPL->fetch_param('parse_vars', 'yes');
        $this->EE->TMPL->tagparams['parse_conditionals']  = $this->EE->TMPL->fetch_param('parse_conditionals', 'yes');

        // name and context passed in tagparts?
        if (isset($this->EE->TMPL->tagparts[3]))
        {   
            $this->EE->TMPL->tagparams['context'] = $this->EE->TMPL->tagparts[2];
            $this->EE->TMPL->tagparams['name'] = $this->EE->TMPL->tagparts[3];  
        }
        elseif(isset($this->EE->TMPL->tagparts[2]))
        {
            $this->EE->TMPL->tagparams['name'] = $this->EE->TMPL->tagparts[2];
        }

        // default to processing embeds at end
        $this->EE->TMPL->tagparams['process'] = $this->EE->TMPL->fetch_param('process', 'end');

        // is this a static template?
        if ( $this->EE->TMPL->tagparams['process'] !== 'static')
        {   
            // non-static templates are assigned to the template bundle by default
            $this->EE->TMPL->tagparams['bundle'] = $this->EE->TMPL->fetch_param('bundle', 'template');

            // by default, parse the template when it is retrieved from the database (like a standard EE embed)
            $this->EE->TMPL->tagparams['parse_stage'] = $this->EE->TMPL->fetch_param('parse_stage', 'get');
        }
        else
        {
            // mandatory params for static templates
            $this->EE->TMPL->tagparams['bundle']  = 'static'; // must be assigned to the static bundle
            $this->EE->TMPL->tagparams['process'] = 'end';
            $this->EE->TMPL->tagparams['context'] = "@URI"; // must be in the context of current URI
            $this->EE->TMPL->tagparams['parse_stage'] = "set"; // static templates must be pre-parsed
            $this->EE->TMPL->tagparams['refresh'] = "0"; // static templates can never expire

            // as this is the full rendered output of a template, check that we should really be saving it
            if ( ! $this->_is_cacheable())
            {
                $this->EE->TMPL->tagparams['save'] = 'no';
            }
        }
        
        // set default parameter values for template files
        
        // set a parse depth of 4
        $this->EE->TMPL->tagparams['parse_depth'] = $this->EE->TMPL->fetch_param('parse_depth', 4);
        
        // don't replace the variable by default (only read from file once)
        // note: file syncing can be forced by setting stash_file_sync = TRUE in config
        $this->EE->TMPL->tagparams['replace'] = $this->EE->TMPL->fetch_param('replace', 'no');
        
        // set priority to 0 by default, so that embeds come before post-processed variables
        $this->EE->TMPL->tagparams['priority'] = $this->EE->TMPL->fetch_param('priority', '0');
        
        // initialise?
        $init = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('init', 'yes'));
        
        // re-initialise parameters, unless disabled by init parameter
        if ($init)
        {
            $this->init();
        }
        else
        {
            $this->process = 'inline';
        }
        
        // permitted parameters for embeds
        $reserved_vars = array(
            'name', 
            'context', 
            'scope', 
            'file', 
            'file_name',
            'parse_stage',
            'save', 
            'refresh',
            'replace',
            'parse_tags',
            'parse_depth',
            'parse_vars',
            'parse_conditionals',
            'process',
            'priority',
            'output',
            'embed_vars',
            'bundle'
        );
        
        // save stash embed vars passed as parameters in the form stash:my_var which we'll
        // inject later into the stash array for replacement, so remove the stash: prefix
        $params = $this->EE->TMPL->tagparams;

        foreach ($params as $key => $val)
        {
            if (strncmp($key, 'stash:', 6) == 0)
            {
                $this->EE->TMPL->tagparams['embed_vars'][substr($key, 6)] = $val;
            }
        }
    
        return $this->_run_tag('get', $reserved_vars);
    }


    // ----------------------------------------------------------

    /**
     * Cache tagdata to db and link it to the current URL context
     *
     * @access public
     * @return string 
     */
    public function cache()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:cache}
        ...
        {/exp:stash:cache}
        */

        // default to processing at end
        $this->EE->TMPL->tagparams['process'] = $this->EE->TMPL->fetch_param('process', 'end');

        // process as a static cache?
        if ( $this->EE->TMPL->tagparams['process'] == 'static')
        {   
            return $this->static_cache($this->EE->TMPL->tagdata);
        }

        // default name for cached items is 'cache'
        $this->EE->TMPL->tagparams['name'] = $this->EE->TMPL->fetch_param('name', 'cache'); 

        // cached items are saved to the template bundle by default, allow this to be overridden
        $this->EE->TMPL->tagparams['bundle'] = $this->EE->TMPL->fetch_param('bundle', 'template'); 

        // by default, parse on both set and get (i.e. so partial caching is possible)
        $this->EE->TMPL->tagparams['parse_stage'] = $this->EE->TMPL->fetch_param('parse_stage', 'both');

        // key_name format for cached items is @URI:context:name, where @URI is the current page URI
        // thus context is always @URI, and name must be set to context:name
        if ( $context = $this->EE->TMPL->fetch_param('context', FALSE))
        {
            $this->EE->TMPL->tagparams['name'] = $this->_parse_context($context) . ':' . $this->EE->TMPL->tagparams['name'];
        }

        // set a default parse depth of 4
        $this->EE->TMPL->tagparams['parse_depth'] = $this->EE->TMPL->fetch_param('parse_depth', 4);
        
        // don't replace the variable by default
        $this->EE->TMPL->tagparams['replace'] = $this->EE->TMPL->fetch_param('replace', 'no');
        
        // set a high priority by default so the tag is processed later than other post-processed vars (except static cached items)
        $this->EE->TMPL->tagparams['priority'] = $this->EE->TMPL->fetch_param('priority', '999998');

        // set a default refresh of 0 (never)
        $this->EE->TMPL->tagparams['refresh'] = $this->EE->TMPL->fetch_param('refresh', 0);
        
        // mandatory parameter values for cached items
        $this->EE->TMPL->tagparams['context']             = "@URI";
        $this->EE->TMPL->tagparams['scope']               = 'site';
        $this->EE->TMPL->tagparams['save']                = 'yes';
        $this->EE->TMPL->tagparams['parse_tags']          = 'yes';
        $this->EE->TMPL->tagparams['parse_vars']          = 'yes';
        $this->EE->TMPL->tagparams['parse_conditionals']  = 'yes';
        $this->EE->TMPL->tagparams['output']              = 'yes';
        $this->process = 'end';

        // re-initialise Stash with the new params
        $this->init();

        // permitted parameters for cache
        $reserved_vars = array(
            'name', 
            'context', 
            'scope', 
            'parse_stage',
            'save', 
            'refresh',
            'replace',
            'parse_tags',
            'parse_depth',
            'parse_vars',
            'parse_conditionals',
            'process',
            'priority',
            'output',
            'bundle'
        );
        
        return $this->_run_tag('set', $reserved_vars);
    }

    // ----------------------------------------------------------

    /**
     * Cache a template to file and link it to the current URL context
     *
     * @access public
     * @param  string $output additional tagdata to return to the template along with the placeholder
     * @return string 
     */
    public function static_cache($output='')
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:static} or {exp:stash:static_cache}
        */

        // default name for static cached items is 'static'
        $this->EE->TMPL->tagparams['name'] = $this->EE->TMPL->fetch_param('name', 'static'); 

        // format for key_name for cached items is @URI:context:name, where @URI is the current page URI
        // thus context is always @URI, and name must be set to context:name
        if ( $context = $this->EE->TMPL->fetch_param('context', FALSE))
        {
            $this->EE->TMPL->tagparams['name'] = $this->_parse_context($context) . ':' . $this->EE->TMPL->tagparams['name'];
        }

        $this->process = 'end';
        $this->priority = '999999'; //  should be the last thing post-processed (by Stash)

        // has the tag been used as a tag pair? If so, just return to the template so it can be parsed naturally
        if ($this->EE->TMPL->tagdata)
        {
            $output = $this->EE->TMPL->tagdata;
        }

        if ($out = $this->_post_parse('save_output')) return $out . $output;
    }


    // ----------------------------------------------------------

    /**
     * Save the final rendered template output to a static file
     *
     * @access public
     * @param  string $output the rendered template
     * @return string 
     */
    public function save_output($output='')
    {
        // mandatory parameter values for cached output
        $this->EE->TMPL->tagparams['context']   = "@URI";
        $this->EE->TMPL->tagparams['scope']     = 'site';
        $this->EE->TMPL->tagparams['save']      = 'yes';
        $this->EE->TMPL->tagparams['refresh']   = "0";   // static cached items can't expire
        $this->EE->TMPL->tagparams['replace']   = "no";  // static cached items cannot be replaced
        $this->EE->TMPL->tagparams['bundle'] = 'static'; // cached pages in the static bundle are saved to file automatically by the model

        // bundle determines the cache driver
        $this->bundle_id = $this->EE->stash_model->get_bundle_by_name($this->EE->TMPL->tagparams['bundle']);

        // set the entire template data as the tagdata, removing the placeholder for this tag from the output saved to file
        // we need to parse remaining globals since unlike db cached pages, static pages won't pass through PHP/EE again
        $this->EE->TMPL->tagdata = $this->EE->TMPL->parse_globals($output); 

        // as this is the full rendered output of a template, check that we should really be saving it
        if ( ! $this->_is_cacheable())
        {
            $this->EE->TMPL->tagparams['save'] = 'no';
        }
        
        // permitted parameters for cached
        $reserved_vars = array(
            'name', 
            'context', 
            'scope', 
            'save', 
            'refresh',
            'replace',
            'bundle'
        );
    
        return $this->_run_tag('set', $reserved_vars);

        return ''; // remove the placeholder from the output
    }

    // ---------------------------------------------------------    

    /**
     * Check to see if a template (not a fragment) is suitable for caching
     *
     * @access public
     * @return string 
     */
    private function _is_cacheable()
    {
        // Check if we should cache this URI
        if ($_SERVER['REQUEST_METHOD'] == 'POST'     // … POST request
            || $this->EE->input->get('ACT')          // … ACT request
            || $this->EE->input->get('css')          // … css request
            || $this->EE->input->get('URL')          // … URL request
        )
        {
            return FALSE;
        }
        
        return TRUE;
    }
    
    // ---------------------------------------------------------    
    
    /**
     * Parse tagdata
     *
     * @param  array $params an array of key => value pairs representing tag parameters
     * @param  string $value string to parse, defaults to template tagdata
     * @access public
     * @return string 
     */
    public function parse($params = array(), $value=NULL)
    {       
        // is this method being called statically?
        if ( !(isset($this) && get_class($this) == __CLASS__))
        {   
            return self::_static_call(__FUNCTION__, $params, '', '', $value);
        }

        // parse="yes"?
        $this->set_parse_params();

        // default parameter values
        $this->EE->TMPL->tagparams['parse_tags']          = $this->EE->TMPL->fetch_param('parse_tags', 'yes');
        $this->EE->TMPL->tagparams['parse_vars']          = $this->EE->TMPL->fetch_param('parse_vars', 'yes');
        $this->EE->TMPL->tagparams['parse_conditionals']  = $this->EE->TMPL->fetch_param('parse_conditionals', 'yes');
        $this->EE->TMPL->tagparams['parse_depth']         = $this->EE->TMPL->fetch_param('parse_depth', 3);
        
        // postpone tag processing?
        if ( $this->process !== 'inline') 
        {   
            if ($out = $this->_post_parse(__FUNCTION__)) return $out;
        }

        // re-initialise Stash with the new default params
        $this->init();
        
        // do the business
        $this->_parse_sub_template($this->parse_tags, $this->parse_vars, $this->parse_conditionals, $this->parse_depth);
        
        // output the parsed template data?
        $output = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('output', 'yes'));

        if ($output)
        {
            return $this->EE->TMPL->tagdata;
        }
    }


    // ---------------------------------------------------------
        
    /**
     * Unset variable(s) in the current session, optionally flush from db
     *
     * @access public
     * @param  mixed     $params The name of the variable to unset, or an array of key => value pairs
     * @param  string    $type  The type of variable
     * @param  string    $scope The scope of the variable
     * @return void 
     */
    public function destroy($params=array(), $type='variable', $scope='user')
    {
        // is this method being called statically?
        if ( func_num_args() > 0 && !(isset($this) && get_class($this) == __CLASS__))
        {   
            return self::_static_call(__FUNCTION__, $params, $type, $scope);
        }
        
        // register params
        $name = $this->EE->TMPL->fetch_param('name', FALSE);        
        $context = $this->EE->TMPL->fetch_param('context', NULL);
        $scope = strtolower($this->EE->TMPL->fetch_param('scope', $this->default_scope));
        $flush_cache = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('flush_cache', 'yes'));
        $bundle = $this->EE->TMPL->fetch_param('bundle', NULL);
        $bundle_id = $this->EE->TMPL->fetch_param('bundle_id', FALSE);
        
        // narrow the scope to user session?
        $session_id = NULL;

        if ($scope === 'user')
        {
            $session_id = $this->_session_id;
        }
        elseif($scope === 'site')
        {
            $session_id = '_global';
        }

        // named bundle?
        if ( ! is_null($bundle) && ! $bundle_id)
        {
            $bundle_id = $this->EE->stash_model->get_bundle_by_name($bundle);
        }
        
        // unset a single variable, or multiple variables that match a regex
        if ($name)
        {   
            if (preg_match('/^#(.*)#$/', $name))
            {   
                // remove matching variable keys from the session
                foreach($this->_stash as $var_key => $var_val)
                {
                    if (preg_match($name, $var_key))
                    {
                        unset($this->_stash[$var_key]);
                    }
                }

                // remove from db cache?
                if ($flush_cache && $scope !== 'local')
                {
                    // delete variables with key_names that match the regex
                    $this->EE->stash_model->delete_matching_keys(
                        $bundle_id,
                        $session_id, 
                        $this->site_id,
                        trim($name, '#')
                    );
                }
            }
            else
            {
                // a named variable
                if ($context !== NULL && count( explode(':', $name) == 1 ))
                {
                    $name = $context . ':' . $name;
                }
                
                // remove from session
                if ( isset($this->_stash[$name]))
                {
                    unset($this->_stash[$name]);
                }
                
                // remove from db cache?
                if ($flush_cache && $scope !== 'local')
                {
                    // replace '@' placeholders with the current context
                    $stash_key = $this->_parse_context($name);

                    // as we're deleting a specific key, the bundle_id is required
                    $bundle_id = $bundle_id ? $bundle_id : $this->bundle_id;
                    
                    $this->EE->stash_model->delete_key(
                        $stash_key, 
                        $bundle_id,
                        $session_id, 
                        $this->site_id
                    );
                }
            }
        }   
        elseif($scope === 'user' || $scope === 'site' || $scope === 'all')
        {
            // unset ALL user-scoped variables in the current process
            $this->_stash = array();
            
            // remove from cache
            if ($flush_cache)
            {
                $this->EE->stash_model->delete_matching_keys(
                    $bundle_id,
                    $session_id, 
                    $this->site_id
                );
            }
        }
    }


    // ---------------------------------------------------------
    
    /**
     * Flush the variables database cache for the current site (Super Admins only)
     *
     * @access public
     * @return string 
     */
    public function flush_cache()
    {
        if ($this->EE->session->userdata['group_title'] == "Super Admins")
        {
            $this->EE->TMPL->tagparams['scope'] = 'all';
            $this->destroy();
            return $this->EE->lang->line('cache_flush_success');
        }
        else
        {
            // not authorised
            $this->EE->output->show_user_error('general', $this->EE->lang->line('not_authorized'));
        }
    }

    /*
    ================================================================
    Utility methods
    ================================================================
    */
    
    /**
     * Match a regex against a string or array of strings
     *
     * @access private
     * @param string $match A regular expression
     * @param string/array $against array of strings to match regex against
     * @return bool
     */
    private function _matches($match, $against)
    {
        $is_match = TRUE;
        $match = $this->EE->security->entity_decode($match);

        if ( ! is_array($against)) 
        {
            $against = array($against);
        }
        else
        {
            // remove null values
            $against = array_filter($against, 'strlen');
        }
        
        // check every value in the array matches
        foreach($against as $part)
        {
            // convert placeholder null to an empty string before comparing
            if ($part === $this->_list_null)
            {
                $part = '';
            }
            $this->EE->TMPL->log_item('Stash: MATCH '. $match . ' AGAINST ' . $part);
            
            if ( ! preg_match($match, $part))
            {
                $is_match = FALSE;
                break;
            }
        }
        return $is_match;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Retrieve and rebuild list, or optionally part of a list
     *
     * @access public
     * @param  mixed     $params The name of the variable to retrieve, or an array of key => value pairs
     * @param  string    $type  The type of variable
     * @param  string    $scope The scope of the variable
     * @return array
     */
    public function rebuild_list($params='', $type='variable', $scope='user')
    {
        // is this method being called statically?
        if ( func_num_args() > 0 && !(isset($this) && get_class($this) == __CLASS__))
        {   
            return self::_static_call(__FUNCTION__, $params, $type, $scope);
        }

        $sort       = strtolower($this->EE->TMPL->fetch_param('sort', FALSE));
        $sort_type  = strtolower($this->EE->TMPL->fetch_param('sort_type', FALSE)); // string || integer || lowercase
        $orderby    = $this->EE->TMPL->fetch_param('orderby', FALSE);
        $match      = $this->EE->TMPL->fetch_param('match', NULL); // regular expression to each list item against
        $against    = $this->EE->TMPL->fetch_param('against', NULL); // array key to test $match against
        $unique     = $this->EE->TMPL->fetch_param('unique', NULL);
        
        // make sure any parsing is done AFTER the list has been replaced in to the template 
        // not when it's still a serialized array
        $this->parse_complete = TRUE;
        
        // run get() with a safe list of parameters
        $list = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));
        
        // reenable parsing
        $this->parse_complete = FALSE;

        if ($list !== '' && $list !== NULL)
        {
            // explode the list
            $list = explode( $this->_list_delimiter, $list);
        
            foreach($list as $key => &$value)
            {
                $value = $this->_list_row_explode($value);
            }
            unset($value);

            // apply order/sort
            if ($orderby)
            {
                if ($orderby == 'random')
                {
                    // shuffle list order
                    shuffle($list);
                }
                elseif (strncmp($orderby, 'random:', 7) == 0)
                {
                    // shuffle one or more keys in the list, but leave the overall list order unchanged
                    $keys_to_shuffle = explode(',', substr($orderby, 7));
                    foreach($keys_to_shuffle as $key_shuffle)
                    {
                        $list = $this->shuffle_list_key($list, $key_shuffle);
                    }
                }
                else
                {
                    // here be dragons (array_multisort)
                    $orderby   = explode('|', preg_replace('#\s+#', '', $orderby));
                    $sort      = explode('|', preg_replace('#\s+#', '', $sort));
                    $sort_type = explode('|', preg_replace('#\s+#', '', $sort_type));

                    // make columns out of rows needed for orderby
                    $columns = array();
                    foreach ($list as $key => $row)
                    {
                        foreach ($orderby as $name)
                        {
                            if ( isset($list[$key][$name]) )
                            {
                                $columns[$name][$key] =& $list[$key][$name];
                            }
                            else
                            {
                                $columns[$name][$key] = null;
                            }
                        }
                    }

                    // create function arguments for multisort
                    $args = array();
                    foreach ($orderby as $i => $name)
                    {
                        $args[] =& $columns[$name]; // column reference 

                        // SORT_ASC is default, only change if desc
                        if (isset($sort[$i]) && $sort[$i]=="desc")
                        {
                            $args[] = SORT_DESC;
                        }

                        // types string, integer, lowercase
                        if (isset($sort_type[$i]))
                        {
                            switch ($sort_type[$i])
                            {
                                case 'string':
                                    $args[] = SORT_STRING;
                                    break;
                                case 'integer': case 'numeric':
                                    $args[] = SORT_NUMERIC;
                                    break;
                                case 'lowercase':
                                    $columns[$name] = array_map('strtolower', $columns[$name]);
                                    $args[] = SORT_STRING;  
                                    break;
                                default:
                                    // $args[] = SORT_REGULAR;
                                    break;
                            }
                        }
                    }
                    // last argument, array to sort
                    $args[] =& $list;

                    // sorted
                    call_user_func_array('array_multisort', $args);

                    unset($columns);

                }
            }
            
            // apply sort direction
            if ( ! is_array($sort) && $sort == 'desc')
            {
                $list = array_values(array_reverse($list));
            }
            
            // match/against: match the value of one of the list keys (specified by the against param) against a regex
            if ( ! is_null($match) && preg_match('/^#(.*)#$/', $match) && ! is_null($against))
            {
                $new_list = array();
                
                foreach($list as $key => $value)
                {
                    if ( isset($value[$against]) )
                    {
                        if ($this->_matches($match, $value[$against]))
                        {
                            // match found
                            $new_list[] = $value;
                        }
                    }
                }
                $list = $new_list;
            }

            // re-index array
            $list = array_values($list);
            
            // ensure we have unique rows?
            if ($unique !== NULL)
            {
                if ( FALSE === (bool) preg_match('/^(0|off|no|n)$/i', $unique))
                {
                    if ( FALSE === (bool) preg_match('/^(1|on|yes|y)$/i', $unique))
                    {
                        // unique across a single column
                        $unique_list = array();
                        $index = 0;
                        foreach($list as $key => $value)
                        {   
                            if ( isset($value[$unique]) )
                            {
                                $unique_list[$index] = array(
                                    $unique => $value[$unique]
                                );
                            }
                            ++$index;   
                        }

                        // make a unique list for the column
                        $unique_list = array_map('unserialize', array_unique(array_map('serialize', $unique_list)));

                        // restore original list values for the unique rows
                        $restored_list = array();
                        foreach($unique_list as $key => $value)
                        {
                            $restored_list[] = $list[$key];
                        }
                        $list = $restored_list;
                    }
                    else
                    {
                        // make a unique list
                        $list = array_map('unserialize', array_unique(array_map('serialize', $list)));
                    }
                }
            }
        }
        else
        {
            $list = array(); // make sure we always return an array
        }   
        return $list;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Retrieve {stash:var}{/stash:var} tag pairs and serialize
     *
     * @access private
     * @return void
     */
    private function _serialize_stash_tag_pairs()
    {
        $match   = $this->EE->TMPL->fetch_param('match', NULL); // regular expression to each list item against
        $against = $this->EE->TMPL->fetch_param('against', NULL); // array key to test $match against
        
        //  get the stash var pairs values
        $stash_vars = array(); 
     
        foreach($this->EE->TMPL->var_pair as $key => $val)
        {
            if (strncmp($key, 'stash:', 6) ==  0)
            {   
                $pattern = '/'.LD.$key.RD.'(.*)'.LD.'\/'.$key.RD.'/Usi';
                preg_match($pattern, $this->EE->TMPL->tagdata, $matches);
                if (!empty($matches))
                {
                    // don't save a string containing just white space, but be careful to preserve zeros
                    if ( $this->not_empty($matches[1]) || $matches[1] === '0')
                    {
                        $stash_vars[substr($key, 6)] = preg_replace('/'.LD.'stash:[a-zA-Z0-9\-_]+'.RD.'(.*)'.LD.'\/stash:[a-zA-Z0-9\-_]+'.RD.'/Usi', '', $matches[1]);
                    }
                    else
                    {
                        // default key value: use a placeholder to represent a null value
                        $stash_vars[substr($key, 6)] = $this->_list_null;
                    }
                }
            }
        }
        
        // match/against: optionally match against the value of one of the list keys, rather than the whole serialized variable
        if  ( ! is_null($match) 
            && preg_match('/^#(.*)#$/', $match) 
            && ! is_null($against) 
            && isset($stash_vars[$against])
            )
        {
            if ( ! $this->_matches($match, $stash_vars[$against]))
            {
                // match not found, end here
                $this->EE->TMPL->tagdata = '';
                return;
            }
            // disable match/against when setting the variable
            #unset($this->EE->TMPL->tagparams['match']);
            #unset($this->EE->TMPL->tagparams['against']);
        }
    
        // flatten the array into a string
        $this->EE->TMPL->tagdata = $this->_list_row_implode($stash_vars);
    }
    
    // ---------------------------------------------------------
    
    /**
     * @param array $array The array to implode
     * @return string The imploded array
     */ 
    private function _list_row_implode($array) 
    {
        if ( ! is_array( $array ) ) return $array;
        $string = array();
        foreach ( $array as $key => $val ) 
        {
            if ( is_array( $val ) )
            {
                $val = implode( ',', $val );
            }
            $string[] = "{$key}{$this->_list_row_glue}{$val}";
        }
        return implode( $this->_list_row_delimiter, $string );
    }
    
    // ---------------------------------------------------------
    
    /**
     * @param string $string The string to explode
     * @return array The imploded array
     */ 
    private function _list_row_explode($string) 
    {
        $array = explode($this->_list_row_delimiter, $string);
        
        $new_array = array();
        
        foreach ( $array as $key => $val ) 
        {
            $val = explode($this->_list_row_glue, $val);
            
            if (isset($val[1]))
            {
                // replace our null placeholder with an empty string
                if ($val[1] === $this->_list_null)
                {
                    $val[1] = '';
                }
                $new_array[$val[0]] = $val[1];
            }
        }   
        return $new_array;
    }
    
    // ---------------------------------------------------------

    /** 
     * Shuffle a stash list key
     *
     * @access public
     * @param array Multidimensional array to sort
     * @param string Array key to sort on
     * @param string Callback function
     * @return void
     */
    public function shuffle_list_key($arr, $key) 
    {
        $key_list = array();

        // shuffle the key array
        foreach($arr as $row)
        {
            if (isset($row[$key]))
            {
                $key_list[] = $row[$key];
            }
        }
        shuffle($key_list);

        // rebuld the list with the shuffled key
        $i = 0;
        foreach($arr as &$row)
        {
            if (isset($key_list[$i]))
            {
                $row[$key] = $key_list[$i];
            }
            ++$i;
        }
        unset($row);

        return $arr;
    }

    // ---------------------------------------------------------

    /** 
     * Sort a multi-dimensional array by key
     *
     * @access public
     * @param array Multidimensional array to sort
     * @param string Array key to sort on
     * @param string Callback function
     * @return void
     */
    public function sort_by_key($arr, $key, $cmp='sort_by_integer') 
    {
        $this->_key2sort = $key;
        
        uasort($arr, array(__CLASS__, $cmp));
        return ($arr);
    }
    
    // ---------------------------------------------------------
    
    /** 
     * Sort callback function: sort by string
     *
     * @access protected
     * @param array
     * @param array
     */
    protected function sort_by_string($a, $b) 
    {
        return (@strcasecmp($a[$this->_key2sort], $b[$this->_key2sort]));
    }
    
    // --------------------------------------------------------- 
    
    /** 
     * Sort callback function: sort by integer
     *
     * @access protected
     * @param array
     * @param array
     */
    protected function sort_by_integer($a, $b)
    {
        if ($a[$this->_key2sort] == $b[$this->_key2sort]) 
        {
            return 0;
        }
        return ($a[$this->_key2sort] < $b[$this->_key2sort]) ? -1 : 1;
    }
    
    // --------------------------------------------------------- 
    
    /** 
     * get a fraction from a parameter value
     *
     * @access private
     * @param string
     * @param string
     * @param integer
     * @return integer
     */
    private function _parse_fraction($fraction, $offset=0, $total)
    {
        if (strstr($fraction, '/'))
        {
            $fraction = explode('/', $fraction);

            if (isset($fraction[1]) && $fraction[1] > 0)
            {
                $p = $fraction[1]; // the default number of partitions
                $start = 0; // index of the first partition
                $end = $fraction[0]; // index of the last partition

                 // do we have an offset?
                if ($offset)
                {
                    if (strstr($offset, '/'))
                    {
                        // we were passed a fraction
                        $offset = explode('/', $offset);
                    }
                    elseif( intval($offset) === $offset)
                    {
                        // we were passed an integer, convert to a fraction of the total
                        $offset = array($offset, $total);
                    }

                    if (isset($offset[1]) && $offset[1] > 0)
                    {
                        // do the denominators match?
                        if ($offset[1] !== $fraction[1])
                        {
                            // no, find the least common denominator for those numbers
                            $p = $this->_lcd(array($offset[1], $fraction[1]));

                            // multiply the numerators accordingly
                            $offset[0]   = $p / $offset[1] * $offset[0];
                            $fraction[0] = $p / $fraction[1] * $fraction[0];
                        }

                        // update indexes of start/end partitions
                        $start = $offset[0];
                        $end = $start + $fraction[0];
                    }
                    else
                    {
                        $offset = 0;
                    }
                }

                // partition a temporary list
                $partlen = floor($total / $p);
                $partrem = $total % $p;
                $partition = array();
                $mark = 0;
                $list  = array_fill(0, $total, 0);

                for($px = 0; $px < $p; $px++) 
                {
                    $incr = ($px < $partrem) ? $partlen + 1 : $partlen;
                    $partition[$px] = array_slice($list, $mark, $incr);
                    $mark += $incr;
                }

                unset($list);

                $i = $start;
                $index = 0;

                while ($i < $end) {
                    if (isset($partition[$i]))
                    {
                        $index += count($partition[$i]);
                    }
                    else break;
                    $i++;
                }
                return $index;
            }
        }
        else
        {
            return (int) $fraction;
        }
    }

    // ---------------------------------------------------------

    /** 
     * get the least common denominator for a given array of numbers
     *
     * @access private
     * @param array numbers to compare
     * @param integer the multiplication count
     * @return integer
     */
    private function _lcd($array, $x=1) 
    {            
        $mod_sum = 0;
        static $lcd = 0;
        
        for($int=1; $int < count($array); $int++) 
        {                
            $modulus[$int] = ($array[0]*$x) % ($array[$int]);
            $mod_sum = $mod_sum + $modulus[$int];            
        }
             
        if (!$mod_sum) 
        {
            $lcd = $array[0]*$x;
        } 
        else 
        {
            $this->_lcd($array, $x+1);
        }

        return $lcd;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Replace the current context in a variable name
     *
     * @access private
     * @param string    $name The variable name
     * @return string
     */
    private function _parse_context($name)
    {   
        // replace '@:' with current context name
        if (strncmp($name, '@:', 2) == 0)
        {
            $name = str_replace('@', self::$context, $name);
        }

        // fetch the *unadulterated* URI of the current page
        $ee_uri = new EE_URI;

        // documented as a 'private' method, but not actually. Called in CI_Router so unlikely to ever be made private.
        $ee_uri->_fetch_uri_string(); 
        $ee_uri->_remove_url_suffix();
        $ee_uri->_explode_segments();

        // provide a fallback value for index pages
        $uri = $ee_uri->uri_string();
        $uri = empty($uri) ? $this->EE->stash_model->get_index_key() : $uri;

        // replace '@URI:' with the current URI
        if (strncmp($name, '@URI:', 5) == 0)
        {
            $name = str_replace('@URI', $uri, $name);
        }

        // apply a global variable prefix, if set
        if ( $prefix = $this->EE->config->item('stash_var_prefix'))
        {
            if (strstr($name, ':'))
            {
                $name = str_replace(':', ':' . $prefix, $name);
            }
            else
            {
                $name = $prefix . $name;
            }
        }

        return $name;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Parse template data
     *
     * @access private
     * @param bool  $tags Parse plugin/module tags
     * @param bool  $vars Parse globals (inc. snippets), native stash vars and segments
     * @param bool  $conditionals Parse advanced conditionals
     * @param int   $depth Number of passes to make of the template tagdata
     * @return string
     */
    private function _parse_sub_template($tags = TRUE, $vars = TRUE, $conditionals = FALSE, $depth = 1)
    {   
        $this->EE->TMPL->log_item("Stash: processing inner tags");
            
        // save TMPL values for later
        $tagparams = $this->EE->TMPL->tagparams;
        $tagdata = $this->EE->TMPL->tagdata;
        
        // call the template_fetch_template hook to prep nested stash embeds
        if ($this->EE->extensions->active_hook('template_fetch_template') === TRUE && ! $this->_embed_nested)
        {
            // stash embed vars
            $embed_vars = (array) $this->EE->TMPL->fetch_param('embed_vars', array());      
            $this->EE->session->cache['stash'] = array_merge($this->EE->session->cache['stash'], $embed_vars);
            
            // important: we only want to call Stash's hook, not any other add-ons
            
            // make a copy of the extensions for this hook
            // we'll need to do this manually if extensions property visibility is ever changed to protected or private
            $ext = $this->EE->extensions->extensions['template_fetch_template'];
            
            // temporarily make Stash the only extension
            $this->EE->extensions->extensions['template_fetch_template'] = array(
                array('Stash_ext' => array(
                    'template_fetch_template',
                    '',
                    $this->version
                )));
            
            // call the hook
            $this->EE->extensions->call('template_fetch_template', array(
                'template_data'      => $this->EE->TMPL->tagdata
            ));
            
            // restore original extensions
            $this->EE->extensions->extensions['template_fetch_template'] = $ext;
            unset($ext);
        
            // don't run again for this template
            $this->_embed_nested = TRUE;
        }
        
        // restore original TMPL values
        $this->EE->TMPL->tagparams = $tagparams;
        $this->EE->TMPL->tagdata = $tagdata;
        
        // clone the template object
        $TMPL2 = $this->EE->TMPL;
        unset($this->EE->TMPL);

        // protect content inside {stash:nocache} tags
        $pattern = '/'.LD.'stash:nocache'.RD.'(.*)'.LD.'\/stash:nocache'.RD.'/Usi';
        $TMPL2->tagdata = preg_replace_callback($pattern, array(get_class($this), '_placeholders'), $TMPL2->tagdata);
    
        // parse variables  
        if ($vars)
        {   
            // note: each pass can expose more variables to be parsed after tag processing
            $TMPL2->tagdata = $this->_parse_template_vars($TMPL2->tagdata);
        }

        // parse simple conditionals
        if ($conditionals)
        {
            $TMPL2->tagdata = $TMPL2->parse_simple_segment_conditionals($TMPL2->tagdata);
            $TMPL2->tagdata = $TMPL2->simple_conditionals($TMPL2->tagdata, $this->EE->config->_global_vars);
        }
        
        // Remove any EE comments that might have been exposed before parsing tags
        if (strpos($TMPL2->tagdata, '{!--') !== FALSE) 
        {
            $TMPL2->tagdata = preg_replace("/\{!--.*?--\}/s", '', $TMPL2->tagdata);
        }
        
        // parse tags, but check that there really are unparsed tags in the current shell   
        if ($tags && (strpos($TMPL2->tagdata, LD.'exp:') !== FALSE))
        {
            // parse tags
            $this->EE->TMPL = new EE_Template();
            $this->EE->TMPL->start_microtime = $TMPL2->start_microtime;
            $this->EE->TMPL->template = $TMPL2->tagdata;
            $this->EE->TMPL->tag_data   = array();
            $this->EE->TMPL->var_single = array();
            $this->EE->TMPL->var_cond   = array();
            $this->EE->TMPL->var_pair   = array();
            $this->EE->TMPL->plugins = $TMPL2->plugins;
            $this->EE->TMPL->modules = $TMPL2->modules;
            $this->EE->TMPL->module_data = $TMPL2->module_data;
            $this->EE->TMPL->parse_tags();
            $this->EE->TMPL->process_tags();
            $this->EE->TMPL->loop_count = 0;
    
            $TMPL2->tagdata = $this->EE->TMPL->template;
            $TMPL2->log = array_merge($TMPL2->log, $this->EE->TMPL->log);
        }
        else
        {
            $depth = 1;
        }
    
        $this->EE->TMPL = $TMPL2;   
        unset($TMPL2);
        
        // recursively parse?
        if ( $depth > 1)
        {
            $depth --;
            
            // the merry-go-round... parse the next shell of tags
            $this->_parse_sub_template($tags, $vars, $conditionals, $depth);
        }
        else
        {
            // parse advanced conditionals?
            if ($conditionals)
            {
                // record if PHP is enabled for this template
                $parse_php = $this->EE->TMPL->parse_php;
                
                // parse conditionals
                $this->EE->TMPL->tagdata = $this->EE->TMPL->advanced_conditionals($this->EE->TMPL->tagdata);
                
                // restore original parse_php flag for this template
                $this->EE->TMPL->parse_php = $parse_php;
            }   
            
            // restore content inside {stash:nocache} tags
            foreach ($this->_ph as $index => $val)
            {
                $this->EE->TMPL->tagdata = str_replace('[_'.__CLASS__.'_'.($index+1).']', $val, $this->EE->TMPL->tagdata);
            }   

            // parse EE nocache placeholders {NOCACHE}
            $this->EE->TMPL->tagdata = $this->EE->TMPL->parse_nocache($this->EE->TMPL->tagdata);        
            
            // call the 'template_post_parse' hook
            if ($this->EE->extensions->active_hook('template_post_parse') === TRUE && $this->_embed_nested === TRUE)
            {   
                // make a copy of the extensions for this hook
                $ext = $this->EE->extensions->extensions['template_post_parse'];
            
                // temporarily make Stash the only extension
                $this->EE->extensions->extensions['template_fetch_template'] = array(
                array('Stash_ext' => array(
                    'template_fetch_template',
                    '',
                    $this->version
                )));
                
                // call the hook
                $this->EE->TMPL->tagdata = $this->EE->extensions->call(
                    'template_post_parse',
                    $this->EE->TMPL->tagdata,
                    FALSE, 
                    $this->site_id
                );
                
                // restore original extensions
                $this->EE->extensions->extensions['template_post_parse'] = $ext;
                unset($ext);
            }
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Parse global vars inside a string
     *
     * @access private
     * @param string    $template String to parse
     * @return string
     */
    private function _parse_template_vars($template = '')
    {   
        // globals vars {name}
        if (count($this->EE->config->_global_vars) > 0 && strpos($template, LD) !== FALSE)
        {
            foreach ($this->EE->config->_global_vars as $key => $val)
            {
                $template = str_replace(LD.$key.RD, $val, $template);
            }   
        }
        
        // stash vars {stash:var} 
        // note: due to the order we're doing this, global vars can themselves contain stash vars...
        if (count($this->EE->session->cache['stash']) > 0 && strpos($template, LD.'stash:') !== FALSE)
        {   
            // We want to replace single stash tag not tag pairs such as {stash:var}whatever{/stash:var}
            // because these are used by stash::set() method when capturing multiple variables.
            // So we'll calculate the intersecting keys of existing stash vars and single tags in the template 
            $tag_vars = $this->EE->functions->assign_variables($template);
            $tag_vars = $tag_vars['var_single'];
            
            foreach($this->EE->session->cache['stash'] as $key => $val)
            {
                if (isset($tag_vars['stash:'.$key]))
                {
                    $template = str_replace(LD.'stash:'.$key.RD, $val, $template);
                }
            }
        }
        
        // user variables, in the form {logged_in_[variable]}
        $user_vars  = array(
                    'member_id', 'group_id', 'group_description', 
                    'group_title', 'member_group', 'username', 'screen_name', 
                    'email', 'ip_address', 'location', 'total_entries', 
                    'total_comments', 'private_messages', 'total_forum_posts', 
                    'total_forum_topics', 'total_forum_replies'
                );
                
        foreach ($user_vars as $val)
        {
            if (isset($this->EE->session->userdata[$val]) AND ($val == 'group_description' OR strval($this->EE->session->userdata[$val]) != ''))
            {
                $template = str_replace(LD.'logged_in_'.$val.RD, $this->EE->session->userdata[$val], $template);
            }
        }       
        
        // Parse date format string "constants" 
        if (strpos($template, LD.'DATE_') !== FALSE)
        {   
            $date_constants = array('DATE_ATOM'     =>  '%Y-%m-%dT%H:%i:%s%Q',
                                    'DATE_COOKIE'   =>  '%l, %d-%M-%y %H:%i:%s UTC',
                                    'DATE_ISO8601'  =>  '%Y-%m-%dT%H:%i:%s%Q',
                                    'DATE_RFC822'   =>  '%D, %d %M %y %H:%i:%s %O',
                                    'DATE_RFC850'   =>  '%l, %d-%M-%y %H:%m:%i UTC',
                                    'DATE_RFC1036'  =>  '%D, %d %M %y %H:%i:%s %O',
                                    'DATE_RFC1123'  =>  '%D, %d %M %Y %H:%i:%s %O',
                                    'DATE_RFC2822'  =>  '%D, %d %M %Y %H:%i:%s %O',
                                    'DATE_RSS'      =>  '%D, %d %M %Y %H:%i:%s %O',
                                    'DATE_W3C'      =>  '%Y-%m-%dT%H:%i:%s%Q'
                                    );
            foreach ($date_constants as $key => $val)
            {
                $template = str_replace(LD.$key.RD, $val, $template);
            }
        }
        
        // Current time {current_time format="%Y %m %d %H:%i:%s"} - thanks @objectivehtml
        if (strpos($template, LD.'current_time') !== FALSE && preg_match_all("/".LD."current_time\s+format=([\"\'])([^\\1]*?)\\1".RD."/", $template, $matches))
        {               
            for ($j = 0; $j < count($matches[0]); $j++)
            {   
                if (version_compare(APP_VER, '2.6', '>=')) 
                {           
                    $template = str_replace($matches[0][$j], $this->EE->localize->format_date($matches[2][$j]), $template); 
                }
                else
                {
                    $template = str_replace($matches[0][$j], $this->EE->localize->decode_date($matches[2][$j], $this->EE->localize->now), $template);
                }   
            }
        }
        
        // segment vars {segment_1} etc
        if (strpos( $template, LD.'segment_' ) !== FALSE )
        {
            for ($i = 1; $i < 10; $i++)
            {
                $template = str_replace(LD.'segment_'.$i.RD, $this->EE->uri->segment($i), $template); 
            }
        }
        
        return $template;
    }
    
    // ---------------------------------------------------------
        
    /**
     * Final parsing of the stash variable before output to the template
     *
     * @access private
     * @param string $value the string to parse 
     * @param string $match A regular expression to match against
     * @param string $filter A regular expression to filter by
     * @param string $default fallback value
     * @return string
     */
    private function _parse_output($value = NULL, $match = NULL, $filter = NULL, $default = NULL)
    {
        // parse tags?
        if ( ($this->parse_tags || $this->parse_vars || $this->parse_conditionals) && ! $this->parse_complete)
        {   
            // do parsing
            $this->EE->TMPL->tagdata = $value;
            $this->_parse_sub_template($this->parse_tags, $this->parse_vars, $this->parse_conditionals, $this->parse_depth);
            $value = $this->EE->TMPL->tagdata;
            unset($this->EE->TMPL->tagdata);
        }
    
        // regex match
        if ( $match !== NULL && $value !== NULL )
        {   
            $is_match = $this->_matches($match, $value);

            if ( ! $is_match )
            {
                $value = $default;
            } 
        }
        
        // regex filter
        if ( $filter !== NULL && $value !== NULL)
        {
            preg_match($filter, $value, $found);
            if (isset($found[1]))
            {
                $value = $found[1];
            }
        }
        // apply string manipulations
        $value = $this->_clean_string($value);
        
        return $value;
    }
    
    // ---------------------------------------------------------
    
    /**
     * String manipulations
     *
     * @access private
     * @param string $value the string to parse 
     * @return string
     */
    private function _clean_string($value = NULL)
    {
        // register parameters
        $trim = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('trim'));
        $strip_tags = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('strip_tags'));   
        $strip_curly_braces = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('strip_curly_braces'));   
        $backspace = (int) $this->EE->TMPL->fetch_param('backspace', 0);    
        $strip_unparsed = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('strip_unparsed'));
        
        // support legacy parameter name
        if ( ! $strip_unparsed)
        {
            $strip_unparsed = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('remove_unparsed_vars'));
        }
        
        // trim?
        if ($trim)
        {
            $value  = str_replace( array("\t", "\n", "\r", "\0", "\x0B"), '', trim($value));
        }

        // strip tags?
        if ($strip_tags)
        {
            $value = strip_tags($value);
        }

        // strip curly braces?
        if ($strip_curly_braces)
        {
            $value = str_replace(array(LD, RD), '', $value);
        }
    
        // backspace?
        if ($backspace)
        {
            // backspace can break unparsed conditionals and tags, so lets check for them
            if (strrpos($value, RD, -$backspace) !== false)
            {
                // unparsed var or tag within the backspace range, trim end as far as we safely can
                $value = substr($value, 0, strrpos($value, RD)+1);
            }
            else
            {
                $value = substr($value, 0, -$backspace);
            }
        }
    
        // xss clean?
        if ($this->xss_clean)
        {
            $value = $this->EE->security->xss_clean($value);
        }
        
        // remove leftover placeholder variables {var} (leave stash: vars untouched)
        if ($strip_unparsed)
        {
            $value = preg_replace('/\{\/?(?!\/?stash)[a-zA-Z0-9_\-:]+\}/', '', $value);
        }

        return $value;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Run a Stash module tag with a known set of parameters
     *
     * @access private
     * @param string $method the public Stash method to call    
     * @param array $params the tag parameters to use
     * @return string
     */
    private function _run_tag($method, $params = array())
    {
        // make a copy of the original parameters
        $original_params = $this->EE->TMPL->tagparams;
        
        // array of permitted parameters
        $allowed_params = array_flip($params);
        
        // set permitted params for use
        foreach($allowed_params as $key => &$value)
        {
            if ( isset($this->EE->TMPL->tagparams[$key]))
            {
                $value = $this->EE->TMPL->tagparams[$key];
            }
            else
            {
                unset($allowed_params[$key]);
            }
        }
        
        // overwrite template params with our safe set
        $this->EE->TMPL->tagparams = $allowed_params;
        
        // run the tag if it is public
        if (method_exists($this, $method))
        {
            $reflection = new ReflectionMethod($this, $method);
            if ( ! $reflection->isPublic()) 
            {
                throw new RuntimeException("The called method is not public.");
            }
            $out = $this->$method();
        }
        
        // restore original parameters
        $this->EE->TMPL->tagparams = $original_params;
        
        unset($original_params);
        
        return $out;
    }   
    
    // ---------------------------------------------------------
    
    /** 
     * Replaces nested tag content with placeholders
     *
     * @access private
     * @param array $matches
     * @return string
     */ 
    private function _placeholders($matches)
    {
        $this->_ph[] = $matches[1];
        return '[_'.__CLASS__.'_'.count($this->_ph).']';
    }
    
    // ---------------------------------------------------------
    
    /**
     * process processing our method until template_post_parse hook
     * 
     * @access private
     * @param String    Method name (e.g. display, link or embed)
     * @return Mixed    TRUE if delay, FALSE if not
     */
    private function _post_parse($method)
    {
        // base our needle off the calling tag
        // add a random number to prevent EE caching the tag, if it is used more than once
        $placeholder = md5($this->EE->TMPL->tagproper) . rand();    
                
        if ( ! isset($this->EE->session->cache['stash']['__template_post_parse__']))
        {
            $this->EE->session->cache['stash']['__template_post_parse__'] = array();
        }
        
        if ($this->process == 'end')
        {
            // postpone until end of tag processing
            $cache =& $this->EE->session->cache['stash']['__template_post_parse__'];
        }
        else
        {
            // unknown or impossible post-process stage
            $this->EE->output->show_user_error('general', sprintf($this->EE->lang->line('unknown_post_process'), $this->EE->TMPL->tagproper, $this->process));
            return;
        }
            
        $this->EE->TMPL->log_item("Stash: this tag will be post-processed on {$this->process}: {$this->EE->TMPL->tagproper}");

        $cache[$placeholder] = array(
            'method'    => $method,
            'tagproper' => $this->EE->TMPL->tagproper,
            'tagparams' => $this->EE->TMPL->tagparams,
            'tagdata'   => $this->EE->TMPL->tagdata,
            'priority'  => $this->priority
        );
            
        // return needle so we can find it later
        return LD.$placeholder.RD;

    }
    
    // ---------------------------------------------------------
    
    /**
     * Prep {if var IN (array)} conditionals
     *
     * Used with the permission of Lodewijk Schutte
     * http://gotolow.com/addons/low-search
     *
     * @access private
     * @param string $tagdata
     * @return String   
     */ 
    private function _prep_in_conditionals($tagdata = '')
    {
        if (preg_match_all('#'.LD.'if (([\w\-_]+)|((\'|")(.+)\\4)) (NOT)?\s?IN \((.*?)\)'.RD.'#', $tagdata, $matches))
        {
            foreach ($matches[0] as $key => $match)
            {
                $left    = $matches[1][$key];
                $operand = $matches[6][$key] ? '!=' : '==';
                $andor   = $matches[6][$key] ? ' AND ' : ' OR ';
                $items   = preg_replace('/(&(amp;)?)+/', '|', $matches[7][$key]);
                $cond    = array();
                foreach (explode('|', $items) as $right)
                {
                    $tmpl   = preg_match('#^(\'|").+\\1$#', $right) ? '%s %s %s' : '%s %s "%s"';
                    $cond[] = sprintf($tmpl, $left, $operand, $right);
                }

                // replace {if var IN (1|2|3)} with {if var == '1' OR var == '2' OR var == '3'}
                $tagdata = str_replace($match, LD.'if '.implode($andor, $cond).RD, $tagdata);
            }
        }
        return $tagdata;
    }

    // ---------------------------------------------------------
    
    /**
     * prep a prefixed no_results block in current template tagdata
     * 
     * @access public
     * @param string $prefix
     * @return String   
     */ 
    function _prep_no_results($prefix)
    {
        if (strpos($this->EE->TMPL->tagdata, 'if '.$prefix.':no_results') !== FALSE 
                && preg_match("/".LD."if ".$prefix.":no_results".RD."(.*?)".LD.'\/'."if".RD."/s", $this->EE->TMPL->tagdata, $match)) 
        {
            if (stristr($match[1], LD.'if'))
            {
                $match[0] = $this->EE->functions->full_tag($match[0], $block, LD.'if', LD.'\/'."if".RD);
            }
        
            $no_results = substr($match[0], strlen(LD."if ".$prefix.":no_results".RD), -strlen(LD.'/'."if".RD));
            $no_results_block = $match[0];
            
            // remove {if prefix:no_results}..{/if} block from template
            $this->EE->TMPL->tagdata = str_replace($no_results_block, '', $this->EE->TMPL->tagdata);
            
            // set no_result variable in Template class
            $this->EE->TMPL->no_results = $no_results;
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * parse and return no_results content
     * 
     * @access public
     * @param string $prefix
     * @return String   
     */ 
    function _no_results()
    {
        if ( ! empty($this->EE->TMPL->no_results))
        {
            // parse the no_results block if it's got content
            $this->EE->TMPL->no_results = $this->_parse_output($this->EE->TMPL->no_results);
        }
        return $this->EE->TMPL->no_results();
    }

    // ---------------------------------------------------------
    
    /**
     * set individual parse parameters if parse="yes"
     * 
     * @access public
     * @param string $prefix
     * @return String   
     */ 
    function set_parse_params()
    {
        $parse = $this->EE->TMPL->fetch_param('parse', NULL);

        if ( NULL !== $parse)
        {
            if ( (bool) preg_match('/1|on|yes|y/i', $parse))
            {
                // parse="yes"
                $this->EE->TMPL->tagparams['parse_tags']          = 'yes';
                $this->EE->TMPL->tagparams['parse_vars']          = 'yes';
                $this->EE->TMPL->tagparams['parse_conditionals']  = 'yes';
            } 
            elseif ( (bool) preg_match('/^(0|off|no|n)$/i', $parse))    
            {   
                // parse="no"
                $this->EE->TMPL->tagparams['parse_tags']          = 'no';
                $this->EE->TMPL->tagparams['parse_vars']          = 'no';
                $this->EE->TMPL->tagparams['parse_conditionals']  = 'no';
            }
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * call a Stash method statically
     * 
     * @access public
     * @param string $method
     * @param mixed $params variable name or an array of parameters
     * @param string $type
     * @param string $scope
     * @param string $value
     * @return void 
     */ 
    private function _static_call($method, $params, $type='variable', $scope='user', $value=NULL)
    {
        // make sure we have a Template object to work with, in case Stash is being invoked outside of a template
        if ( ! class_exists('EE_Template'))
        {
            self::_load_EE_TMPL();
        }
        else
        {
            // make sure we have a clean array if class has already been instatiated
            $this->EE->TMPL->tagparams = array();
        }
        
        if ( is_array($params))
        {
            $this->EE->TMPL->tagparams = $params;
        }
        else
        {
            $this->EE->TMPL->tagparams['name']    = $params;
            $this->EE->TMPL->tagparams['type']    = $type;
            $this->EE->TMPL->tagparams['scope']   = $scope;
        }
        
        if ( ! is_null($value))
        {
            $this->EE->TMPL->tagdata = $value;
        }
    
        // as this function is called statically, we need to get a Stash object instance and run the requested method
        $self = new self(); 
        return $self->{$method}();
    }
    
    // ---------------------------------------------------------
    
    /** 
     * Check if the user agent is a bot
     *
     * @access public
     * @return void
     */ 
    private function _is_bot() 
    {   
        $bot_test = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : (php_sapi_name() === 'cli' ? 'cli' : 'other');
        $is_bot = FALSE;
    
        if (empty($bot_test)) 
        {
            $is_bot = TRUE; // no UA string, assume it's a bot
        }
        else
        {
            // Most active *legitimate* bots will contain one of these strings in the UA
            $bot_list = $this->EE->config->item('stash_bots') ? 
                        $this->EE->config->item('stash_bots') : 
                        array('bot', 'crawl', 'spider', 'archive', 'search', 'java', 'yahoo', 'teoma');
    
            foreach($bot_list as $bot) 
            {
                if(strpos($bot_test, $bot) !== FALSE) 
                {
                    $is_bot = TRUE;
                    break; // stop right away to save processing
                }
            }
        }
        return $is_bot;
    }
    
    // ---------------------------------------------------------
    
    /**
     * set the stash cookie
     * 
     * @access private
     * @param string $unique_id the session ID
     * @param integer $expire cookie duration in seconds
     * @return void 
     */ 
    private function _set_stash_cookie($unique_id)
    { 
        $cookie_data = serialize(array(
           'id' => $unique_id,
           'dt' => $this->EE->localize->now
        ));
      
        $this->EE->functions->set_cookie($this->stash_cookie, $cookie_data, $this->stash_cookie_expire);
    }
    
    /**
     * get the stash cookie
     * 
     * @access private
     * @return boolean/array    
     */ 
    private function _get_stash_cookie()
    { 
        $cookie_data = @unserialize($this->EE->input->cookie($this->stash_cookie));
        
        if ($cookie_data !== FALSE)
        {
            // make sure the cookie hasn't been monkeyed with
            if ( isset($cookie_data['id']) && isset($cookie_data['dt']))
            {
                // make sure we have a valid 40-character SHA-1 hash
                if ( (bool) preg_match('/^[0-9a-f]{40}$/i', $cookie_data['id']) )
                {
                    // make sure we have a valid timestamp
                    if ( ((int) $cookie_data['dt'] === $cookie_data['dt']) 
                        && ($cookie_data['dt'] <= PHP_INT_MAX)
                        && ($cookie_data['dt'] >= ~PHP_INT_MAX) )
                    {
                        return $cookie_data;
                    }
                }
            }
        }
        return FALSE;
    }

}

/* End of file mod.stash.php */
/* Location: ./system/expressionengine/third_party/stash/mod.stash.php */