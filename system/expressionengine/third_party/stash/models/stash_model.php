<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Set and get template variables, EE snippets and persistent variables.
 *
 * @package             Stash
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2012 Hallmark Design
 * @license             http://creativecommons.org/licenses/by-nc-sa/3.0/
 * @link                http://hallmark-design.co.uk
 */

class Stash_model extends CI_Model {
    
    public $EE;
    
    protected static $keys = array();
    protected static $inserted_keys = array();

    // default bundle types
    protected static $bundle_ids = array(
            'default'   => 1,
            'template'  => 2,
            'static'    => 3
    );

    // name of cache files
    private $_static_file = 'index.html';

    // placeholder for indexes
    private $_index_key = '[index]';

    function __construct()
    {
        parent::__construct();
        $this->EE = get_instance();
    }
    
    /**
     * Insert key for user session
     *
     * @param string $key
     * @param string $session_id
     * @param integer $site_id
     * @param integer $expire
     * @param string $parameters
     * @param string $label
     * @param integer $bundle_id
     * @return integer
     */
    function insert_key($key, $bundle_id = 1, $session_id, $site_id = 1, $expire = 0, $parameters = '', $label = '')
    {
        $data = array(  
                'key_name'      => $key,
                'bundle_id'     => $bundle_id,
                'session_id'    => $session_id,
                'site_id'       => $site_id,
                'created'       => $this->EE->localize->now,
                'expire'        => $expire,
                'parameters'    => $parameters,
                'key_label'     => $label
        );
        
        if ( $result = $this->db->insert('stash', $data) )
        {
            // store a record of the newly created key
            $cache_key = $key . '_'. $bundle_id .'_' .$site_id . '_' . $session_id;
            self::$inserted_keys[] = $cache_key;
            
            // cache result to eliminate need for a query in future gets
            self::$keys[$cache_key] = $parameters;

            // write to static file cache?
            $this->write_static_cache($key, $bundle_id, $site_id, $parameters);
            
            // return insert id
            return $this->db->insert_id();
        }
        else
        {
            return FALSE;
        }
    }
    
    /**
     * Check to see if a key has been inserted within this page load
     *
     * @param string $cache_key
     * @return bool
     */
    function is_inserted_key($cache_key)
    {
        return in_array($cache_key, self::$inserted_keys) ? TRUE : FALSE;
    }
    
    /**
     * Update key for user session
     *
     * @param string $key
     * @param string $session_id
     * @param integer $site_id
     * @param integer $expire
     * @param string $parameters
     * @return boolean
     */
    function update_key($key, $bundle_id = 1, $session_id = '', $site_id = 1, $expire = 0, $parameters = NULL)
    {
        $data = array(
                'created'       => $this->EE->localize->now,
                'expire'        => $expire
        );
        
        if ($parameters !== NULL)
        {
            $data += array('parameters' => $parameters);
        }
        
        $this->db->where('key_name', $key)
                 ->where('bundle_id', $bundle_id)
                 ->where('site_id', $site_id);               
                
        if ( ! empty($session_id))
        {
            $this->db->where('session_id', $session_id);
        }       
        
        if ($result = $this->db->update('stash', $data))                   
        {       
            if ( (bool) $this->db->affected_rows())
            {
                // success - update cache
                $cache_key = $key . '_'. $bundle_id .'_' .$site_id . '_' . $session_id;
                self::$keys[$cache_key] = $parameters;

                // write to static file cache?
                $this->write_static_cache($key, $bundle_id, $site_id, $parameters);

                return TRUE;
            }   
        }
        else
        {
            return FALSE;
        }
    }
    
    /**
     * Refresh key for user session
     *
     * @param string $key
     * @param string $session_id
     * @param integer $site_id
     * @param integer $refresh Seconds to expiry date
     * @return boolean
     */
    function refresh_key($key, $bundle_id = 1, $session_id = '', $site_id = 1, $refresh)
    {   
        $expire = $this->EE->localize->now + $refresh;
        return $this->update_key($key, $bundle_id, $session_id, $site_id, $expire);
    }
    
    /**
     * Get key value
     *
     * @param string $key
     * @param string $session_id
     * @param integer $site_id
     * @param string $col
     * @return string
     */
    function get_key($key, $bundle_id = 1, $session_id = '', $site_id = 1, $col = 'parameters')
    {
        $cache_key = $key . '_'. $bundle_id .'_' .$site_id . '_' . $session_id;
        
        if ( ! isset(self::$keys[$cache_key]))
        {
            $this->db->select($col .', created, expire')
                     ->from('stash')
                     ->where('key_name', $key)
                     ->where('bundle_id', $bundle_id)
                     ->where('site_id', $site_id)
                     ->limit(1);
            if ( ! empty($session_id))
            {
                $this->db->where('session_id', $session_id);
            }
        
            $result = $this->db->get();
        
            if ($result->num_rows() == 1) 
            {
                // if this key expires soon and is scoped to user session, refresh it
                if ($result->row('expire') > 0 && $session_id != '_global' && ! empty($session_id))
                {
                    $refresh = $result->row('expire') - $result->row('created'); // refresh period  (seconds)
                    $expire  = $result->row('expire') - $this->EE->localize->now; // time to expiry (seconds)
            
                    if ( ($refresh / $expire) > 2 ) 
                    {
                        // more than half the refresh time has passed since the last time key was accessed
                        // so let's refresh the key expiry
                        $this->refresh_key($key, $bundle_id, $session_id, $site_id, $refresh);  
                    }
                }
                            
                // cache result
                self::$keys[$cache_key] = $result->row($col);
            }
            else
            {
                // Cache empty result, this will get overwritten if the key is inserted later on
                // Otherwise it will prevent repeated unecessary queries for empty variables
                self::$keys[$cache_key] = '';
            }
        }
        if (self::$keys[$cache_key] === '')
        {
            return FALSE;
        }
        else
        {
            return self::$keys[$cache_key];
        }
    }
    
    /**
     * Delete key(s) by name, optionally limited to keys registered with the user session
     *
     * @param string $key
     * @param integer/boolean $bundle_id
     * @param string $session_id
     * @param integer $site_id
     * @return boolean
     */
    function delete_key($key, $bundle_id = FALSE, $session_id = NULL, $site_id = 1)
    {
        $this->db->where('key_name', $key)
                 ->where('site_id', $site_id);

        // match a specific bundle
        if ($bundle_id) 
        {
            $this->db->where('bundle_id', $bundle_id);
        }        
                
        if ( ! is_null($session_id))
        {
            $this->db->where('session_id', $session_id);
            
            if ($session_id !== '_global')
            {
                // make sure we only delete the user's variable
                $this->db->limit(1);
            }
        }
        
        // get matching key(s)
        $query = $this->db->select('id, key_name, key_label, bundle_id, session_id')->get('stash');

        if ($query->num_rows() > 0)
        {   
            if ( $this->delete_cache($query->result(), $site_id))
            {
                // deleted, now remove the key from the internal key cache
                $cache_key = $key . '_'. $bundle_id .'_' .$site_id . '_' . $session_id;
            
                if ( isset(self::$keys[$cache_key]))
                {
                    unset(self::$keys[$cache_key]);
                }

                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Delete key(s) matching a regular expression, and/or scope, and/or bundle
     *
     * @param integer/boolean $bundle_id
     * @param string $session_id
     * @param integer $site_id
     * @param string $regex a regular expression
     * @return boolean
     */
    function delete_matching_keys($bundle_id = FALSE, $session_id=NULL, $site_id = 1, $regex=NULL)
    {
        $deleted = FALSE;

        $this->db->where('site_id', $site_id);

        // match a specific bundle
        if ($bundle_id) 
        {
            $this->db->where('bundle_id', $bundle_id);
        }

        // match session_id      
        if ( ! is_null($session_id))    
        {   
            // scope supplied?
            if ($session_id === 'user')
            {
                // all user-scoped variables
                $this->db->where('session_id !=', '_global');
            }
            elseif($session_id === 'site')
            {
                // all site-scoped variables
                $this->db->where('session_id', '_global');
            }
            else
            {
                // a specific user session
                $this->db->where('session_id', $session_id);
            }
        }

        // match key_name regex      
        if ( ! is_null($regex)) 
        {    
            $this->db->where('key_name RLIKE ', $this->db->escape($regex), FALSE);
        
            // get matching keys
            $query = $this->db->select('id, key_name, key_label, bundle_id, session_id')->get('stash');

            if ($query->num_rows() > 0)
            {
                $deleted = $this->delete_cache($query->result(), $site_id);
            }
        }
        elseif ($this->db->delete('stash'))
        {
            // -------------------------------------
            // 'stash_delete' hook
            // -------------------------------------
            if ($this->EE->extensions->active_hook('stash_delete') === TRUE)
            {
                $this->EE->extensions->call('stash_delete', array(
                    'key_name'      => FALSE, 
                    'key_label'     => FALSE, 
                    'bundle_id'     => $bundle_id, 
                    'session_id'    => $session_id, 
                    'site_id'       => $site_id
                ));
            }

            // delete entire static cache for this site if bundle is 'static' or not specified
            // and scope is 'site', 'all' or not specified
            if ( ! $bundle_id || $this->_can_static_cache($bundle_id) )
            {
                if ( is_null($session_id) || $session_id === 'site' || $session_id === 'all')
                {
                    $this->_delete_dir('/', $site_id);
                }
            }
            $deleted = TRUE;
        }

        if ($deleted)
        {
            // deleted sucessfully, reset the static key cache
            self::$keys = array();
        }

        return $deleted;    
    }


    /**
     * Delete an array of variables in a given site
     *
     * @param array $vars An array of objects
     * @param integer $site_id
     * @return boolean
     */
    protected function delete_cache($vars, $site_id = 1)
    {
        $ids = array();

        foreach ($vars as $row)
        {
            $ids[] = $row->id;

            // -------------------------------------
            // 'stash_delete' hook
            // -------------------------------------
            if ($this->EE->extensions->active_hook('stash_delete') === TRUE)
            {
                $this->EE->extensions->call('stash_delete', array(
                    'key_name'      => $row->key_name,
                    'key_label'     => $row->key_label, 
                    'bundle_id'     => $row->bundle_id, 
                    'session_id'    => $row->session_id, 
                    'site_id'       => $site_id
                ));
            }

            // delete any corresponding static cache files, individually
            $this->delete_static_cache($row->key_name, $row->bundle_id, $site_id);
        }

        // delete any db records
        if ($this->EE->db->where_in('id', $ids)->delete('stash')) 
        {
            return TRUE;
        }

        return FALSE;
    }
    
    /**
     * Prune expired keys
     *
     * @return boolean
     */
    function prune_keys()
    {
        if ($result = $this->db->delete('stash', array(
                'expire <'  => $this->EE->localize->now, 
                'expire !=' => '0'
        )))
        {
            return TRUE;
        }
        else
        {
            return FALSE;
        }
    }
    
    /**
     * Get a bundle id from the name
     *
     * @param string $bundle
     * @return integer
     */
    function get_bundle_by_name($bundle)
    {
        $cache_key = $bundle;
        
        if ( ! isset(self::$bundle_ids[$cache_key]))
        {
            $result = $this->db->select('id')
                     ->from('stash_bundles')
                     ->where('bundle_name', $bundle)
                     ->limit(1)
                     ->get();
                
            if ($result->num_rows() == 1) 
            {
                self::$bundle_ids[$cache_key] = $result->row('id');
            }
            else
            {
                self::$bundle_ids[$cache_key] = FALSE;
            }
        }       
        return self::$bundle_ids[$cache_key];
    }


    /**
     * Get a bundle name from the id
     *
     * @param integer $bundle_id
     * @return integer
     */
    function get_bundle_by_id($bundle_id)
    {
        if ( ! $bundle_name = array_search($bundle_id, self::$bundle_ids) )
        {
            $result = $this->db->select('bundle_name')
                     ->from('stash_bundles')
                     ->where('id', $bundle_id)
                     ->limit(1)
                     ->get();
                
            if ($result->num_rows() == 1) 
            {
                $bundle_name = $result->row('bundle_name');
                self::$bundle_ids[$bundle_name] = $bundle_id;
            }
            else
            {
                $bundle_name = FALSE;
            }
        }

        return $bundle_name;
    }
    
    /**
     * Insert a bundle
     *
     * @param string $bundle
     * @param integer $site_id
     * @param integer $bundle_label
     */
    function insert_bundle($bundle, $bundle_label = '')
    {
        $data = array(  
                'bundle_name'   => $bundle,
                'bundle_label'  => $bundle_label
        );
        
        if ( $result = $this->db->insert('stash_bundles', $data) )
        {
            return $this->db->insert_id();
        }
        else
        {
            return FALSE;
        }
    }
    
    /**
     * Check if there is at least one entry for the bundle in this site
     *
     * @return integer
     */
    function bundle_entry_exists($bundle_id, $site_id = 1)
    {
        $result = $this->db->select('id')
                 ->from('stash')
                 ->where('bundle_id', $bundle_id)
                 ->where('site_id', $site_id)
                 ->limit(1)
                 ->get();
                
        if ($result->num_rows() == 1) 
        {
            return TRUE;
        }
        else
        {
            return FALSE;
        }
    }
    
    /**
     * Count the number of entries for a given bundle
     *
     * @return boolean
     */
    function bundle_entry_count($bundle_id, $site_id = 1)
    {
        $result = $this->db->select('COUNT(id) AS row_count')
                 ->from('stash')
                 ->where('bundle_id', $bundle_id)
                 ->where('site_id', $site_id)
                 ->get();
                
        if ($result->num_rows() == 1) 
        {
            return $result->row('row_count');
        }
        else
        {
            return 0;
        }
    }


    /*
    ================================================================
    Static cache handler
    ================================================================
    */

    /**
    * Check if the variable can be cached as a static file and write it
     *
     * @param string $key
     * @param integer $bundle_id
     * @param string $parameters
     * @return boolean
     */
    protected function write_static_cache($key, $bundle_id, $site_id, $parameters)
    {
        // write to static file cache?
        if ($this->_can_static_cache($bundle_id))
        {
            // extract the associated uri from the variable key
            $uri = $this->parse_uri_from_key($key);

            // cache that mother
            return $this->_write_file($uri, $site_id, $parameters);
        }

        return FALSE;
    }

    /**
    * Delete the associated static file for a given variable
     *
     * @param integer $key
     * @param string $bundle_id
     * @return boolean
     */
    protected function delete_static_cache($key, $bundle_id, $site_id)
    {
        // write to static file cache?
        if ($this->_can_static_cache($bundle_id))
        {
            // extract the associated uri from the variable key
            $uri = $this->parse_uri_from_key($key);

            // delete the cache file
            return $this->_delete_file($uri, $site_id);
        }

        return FALSE;
    }

    /**
     * Write a static file
     *
     * @param string $uri
     * @param string $parameters
     * @return boolean
     */
    private function _write_file($uri, $site_id, $parameters = NULL)
    {
        $this->EE->load->helper('file');

        if ($path = $this->_path($uri, $site_id))
        {
            // Make sure the directory exists
            if (file_exists($path) || @mkdir($path, 0777, TRUE))
            {
                // Write the static file
                if (@write_file($path.$this->_static_file, $parameters, 'w+'))
                {
                    return TRUE;
                }

            }
        }
        return FALSE;
    }

    /**
    * Delete a static file
    * 
    * @param string $uri
    * @return boolean
    */
    private function _delete_file($uri = '/', $site_id)
    {
        if ($path = $this->_path($uri, $site_id) && @unlink($this->_path($uri, $site_id).$this->_static_file))
        {
            return TRUE;
        }
        return FALSE;
    }

    /**
    * Recursively delete directories and files in a static cache directory
    * 
    * @param string $uri
    * @return boolean
    */
    private function _delete_dir($uri, $site_id)
    {
        $this->EE->load->helper('file');

        if ( $path = $this->_path($uri, $site_id) && delete_files($this->_path($uri, $site_id), TRUE) )
        {
            return TRUE;
        }
        return FALSE;
    }

   /**
    * Returns the path to the cache directory, if it exists
    * 
    * @param string $uri
    * @return string/boolean
    */
    private function _path($uri = '/', $site_id)
    {
        // Get parts
        $path = $this->EE->config->item('stash_static_basepath');

        // Check the supplied cache path exists
        if ( ! file_exists($path))
        {
            return FALSE;
        }

        // Build the path
        return trim($path.'/'.$site_id.'/'.trim($uri, '/')).'/';
    }

   /**
    * Check that static cache is enabled, and the bundle is static
    * 
    * @param integer $bundle_id
    * @return boolean
    */
    private function _can_static_cache($bundle_id)
    {
        if ( $this->EE->config->item('stash_static_cache_enabled'))
        {
            if ($this->get_bundle_by_id($bundle_id) === 'static')
            {
                return TRUE;
            }
        }

        return FALSE;
    }


    /*
    ================================================================
    Utility
    ================================================================
    */

   /**
    * Parse a URI from a stash variable key (typically, the uri of a cached page)
    * 
    * @param string $key
    * @return string
    */
    public function parse_uri_from_key($key)
    {
        $uri = explode(':', $key);
        return $uri[0] == $this->_index_key ? '' : $uri[0];
    }

   /**
    * Getter: returns the index key placeholder
    * 
    * @return string
    */
    public function get_index_key()
    {
        return $this->_index_key;
    }
 
}

/* End of file stash_model.php */
/* Location: ./system/expressionengine/third_party/stash/models/stash_model.php */