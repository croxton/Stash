<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Set and get template variables, EE snippets and persistent variables.
 *
 * @package             Stash
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2019 Hallmark Design
 * @link                http://hallmark-design.co.uk
 */

class Stash_model extends CI_Model {

    protected static $keys = array();
    protected static $inserted_keys = array();
    protected static $queue;

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

    public function __construct()
    {
        parent::__construct();

        // batch processing of queued queries
        self::$queue = new stdClass();
        self::$queue->inserts = array();
        self::$queue->updates = array();

        // Process the queue on shutdown.
        // Note that as PHP-FPM does not support this function,
        // we call process_queue() in Stash_ext::template_post_parse() as well.
        // But, you ask, so why do this at all?
        // Because if you SET/UPDATE a Stash variable outside of an EE
        // template, you can't rely on extension hooks being triggered
        register_shutdown_function(array($this, "process_queue"));
    }

    /**
     * Insert key for user session
     *
     * @param string $key
     * @param integer $bundle_id
     * @param string|null $session_id
     * @param integer $site_id
     * @param integer $expire
     * @param string $parameters
     * @param string $label
     * @return boolean
     */
    public function insert_key(string $key, int $bundle_id = 1, string $session_id = NULL, int $site_id = 1, int $expire = 0, string $parameters = '', string $label = '')
    {
        $cache_key = $key . '_'. $bundle_id .'_' .$site_id . '_' . $session_id;

        $data = array(
            'key_name'      => $key,
            'bundle_id'     => $bundle_id,
            'session_id'    => $session_id,
            'site_id'       => $site_id,
            'created'       => ee()->localize->now,
            'expire'        => $expire,
            'parameters'    => $parameters,
            'key_label'     => $label
        );

        if ($result = $this->queue_insert('stash', $cache_key, $data))
        {
            // store a record of the key
            self::$inserted_keys[] = $cache_key;

            // cache result to eliminate need for a query in future gets
            self::$keys[$cache_key] = $parameters;

            return TRUE;
        }

        return FALSE;
    }

    /**
     * Check to see if a key has been inserted within this page load
     *
     * @param string $cache_key
     * @return bool
     */
    public function is_inserted_key($cache_key)
    {
        return in_array($cache_key, self::$inserted_keys) ? TRUE : FALSE;
    }

    /**
     * Update key for user session
     *
     * @param string $key
     * @param int $bundle_id
     * @param string $session_id
     * @param integer $site_id
     * @param integer $expire
     * @param string|null $parameters
     * @return boolean
     */
    public function update_key(string $key, int $bundle_id = 1, string $session_id = '', int $site_id = 1, int $expire = 0, string $parameters = NULL)
    {
        $cache_key = $key . '_'. $bundle_id .'_' .$site_id . '_' . $session_id;

        $data = array(
            'key_name'  => $key,
            'bundle_id' => $bundle_id,
            'site_id'   => $site_id,
            'created'   => ee()->localize->now,
            'expire'    => $expire,
        );

        if ($parameters !== NULL)
        {
            $data += array('parameters' => $parameters);
        }

        if ( ! empty($session_id))
        {
            $data += array('session_id' => $session_id);
        }

        if ($result = $this->queue_update('stash', $cache_key, $data))
        {
            // update cache
            self::$keys[$cache_key] = $parameters;

            return TRUE;
        }

        return FALSE;
    }

    /**
     * Refresh key for user session
     *
     * @param string $key
     * @param int $bundle_id
     * @param string $session_id
     * @param integer $site_id
     * @param integer $refresh Seconds to expiry date
     * @return boolean
     */
    public function refresh_key(string $key, int $bundle_id = 1, string $session_id = '', int $site_id = 1, int $refresh = 0)
    {
        $expire = ee()->localize->now + $refresh;
        return $this->update_key($key, $bundle_id, $session_id, $site_id, $expire);
    }

    /**
     * Get key value
     *
     * @param string $key
     * @param int $bundle_id
     * @param string $session_id
     * @param integer $site_id
     * @param string $col
     * @return bool|string
     */
    public function get_key(string $key, int $bundle_id = 1, string $session_id = '', int $site_id = 1, string $col = 'parameters')
    {
        $cache_key = $key . '_' . $bundle_id .'_' . $session_id . $site_id . '_' . $col;

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

            if ($result->num_rows() === 1)
            {
                $key_created = $result->row('created');
                $key_expire = $result->row('expire');

                // if this key expires soon and is scoped to user session, refresh it
                if ($key_expire > 0 && $session_id !== '_global' && ! empty($session_id))
                {
                    $refresh = $key_expire - $key_created; // refresh period  (seconds)
                    $expire  = $key_expire - ee()->localize->now; // time to expiry (seconds)

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
                // Otherwise it will prevent repeated unnecessary queries for empty variables
                self::$keys[$cache_key] = '';
            }
        }
        if (self::$keys[$cache_key] === '')
        {
            return FALSE;
        }

        return self::$keys[$cache_key];
    }

    /**
     * Get key expiry date
     *
     * @param string $key
     * @param string $session_id
     * @param integer $site_id
     * @return int | boolean
     */
    public function get_key_expiry(string $key, int $bundle_id = 1, string $session_id = '', int $site_id = 1)
    {
        $cache_key = $key . '_'. $bundle_id .'_' .$site_id . '_' . $session_id;

        if ( isset(self::$keys[$cache_key]))
        {
            return self::$keys[$cache_key]['expire'];
        }

        return FALSE;
    }

    /**
     * Delete key(s) by name, optionally limited to keys registered with the user session
     *
     * @param string $key
     * @param bool|integer $bundle_id
     * @param string|null $session_id
     * @param integer $site_id
     * @param integer $invalidate Delay until cached item expires (seconds)
     * @return boolean
     */
    public function delete_key(string $key, $bundle_id = FALSE, string $session_id = NULL, int $site_id = 1, int $invalidate=0)
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
        $query = $this->db->select('id, site_id, key_name, key_label, session_id, bundle_id')->get('stash');

        if ($query->num_rows() > 0)
        {
            if ( $this->delete_cache($query->result(), TRUE, $invalidate))
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
     * @param int|bool $bundle_id
     * @param string|null $session_id
     * @param integer $site_id
     * @param string|null $regex a regular expression
     * @param integer $invalidate Delay until cached item expires (seconds)
     * @return boolean
     */
    public function delete_matching_keys($bundle_id = FALSE, string $session_id=NULL, int $site_id = 1, string $regex=NULL, int $invalidate=0)
    {
        $deleted = FALSE; // have keys been deleted from the database?
        $clear_static = TRUE; // attempt to delete corresponding *individual* static cache files?

        // Can we clear the entire static cache in one go (to minimize disk access)?
        if (ee()->config->item('stash_static_cache_enabled')
            && $regex === NULL
            && $invalidate === 0)
        {
            if ( ! $bundle_id || $this->_can_static_cache($bundle_id) )
            {
                if ( is_null($session_id) || $session_id === 'site' || $session_id === 'all')
                {
                    $this->_delete_dir('/', $site_id);
                    $clear_static = FALSE;
                }
            }
        }

        // clear the db
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
        }

        // get matching keys
        $query = $this->db->select('id, site_id, key_name, key_label, session_id, bundle_id')->get('stash');

        if ($query->num_rows() > 0)
        {
            $deleted = $this->delete_cache($query->result(), $clear_static, $invalidate);
        }

        if ($deleted)
        {
            // deleted successfully, reset the static key cache
            self::$keys = array();
        }

        return $deleted;
    }

    /**
     * Delete an array of variables in a given site
     *
     * @param array $vars An array of objects
     * @param boolean $clear_static Clear the static cache too, for variables in the static bundle?
     * @param integer $invalidate Delay until cached item expires (seconds)
     * @param bool $call_hook
     * @return boolean
     */
    protected function delete_cache(array $vars, bool $clear_static = TRUE, int $invalidate = 0, bool $call_hook = TRUE)
    {
        // get a list of variable ids
        $ids = array();
        foreach ($vars as $row)
        {
            $ids[] = $row->id;
        }

        // delete any db records
        $result = FALSE;

        if ($invalidate > 0)
        {
            // "Soft delete" - update variables to expire at random intervals within
            // the invalidation period and so help prevent cache stampedes.
            // When the variables are subsequently pruned, this function is triggered
            // again and any corresponding caches will be deleted
            $result = $this->_invalidate($ids, $invalidate);
        }
        else
        {
            // delete immediately
            $result = ee()->db->where_in('id', $ids)->delete('stash');

            // delete corresponding caches
            foreach ($vars as $row)
            {
                if ($clear_static)
                {
                    // delete any corresponding static cache files, individually
                    $this->delete_static_cache($row->key_name, $row->bundle_id, $row->site_id);
                }

                // -------------------------------------
                // 'stash_delete' hook
                // -------------------------------------
                if (ee()->extensions->active_hook('stash_delete') === TRUE
                    && $call_hook === TRUE)
                {
                    ee()->extensions->call('stash_delete', array(
                        'key_name'      => $row->key_name,
                        'key_label'     => $row->key_label,
                        'bundle_id'     => $row->bundle_id,
                        'session_id'    => $row->session_id,
                        'site_id'       => $row->site_id
                    ));
                }
            }
        }

        return $result;
    }

    /**
     * Flush the entire cache for a given site, immediately
     *
     * @param integer $site_id
     * @return boolean
     */
    public function flush_cache(int $site_id = 1)
    {
        // delete all variables saved in the db
        if ($result = ee()->db->where('site_id', $site_id)->delete('stash'))
        {
            // remove all files in the static dir, if static caching is enabled
            if ( ee()->config->item('stash_static_cache_enabled'))
            {
                $this->_delete_dir('/', $site_id);
            }

            // -------------------------------------
            // 'stash_flush_cache' hook
            // -------------------------------------
            if (ee()->extensions->active_hook('stash_flush_cache') === TRUE)
            {
                ee()->extensions->call('stash_flush_cache', $site_id);
            }

            return $result;
        }

        return FALSE;
    }

    /**
     * Update variables to expire at random intervals within the
     * invalidation period and so help prevent cache stampedes
     *
     * @param array $ids An array of variable ids
     * @param integer $period the invalidation period in seconds
     * @return boolean
     */
    private function _invalidate(array $ids, int $period=0)
    {
        $now = ee()->localize->now;

        if (count($ids) > 1)
        {
            // sort low to high
            sort($ids);

            // get the last id value and the count
            $id_end = end($ids);
            $id_count = count($ids)-1;

            // what we're doing here is approximately dividing the expiry delay across the target ids,
            // increasing the delay according the original id value, so that variables
            // generated later in the original template, get regenerated later too
            ee()->db->where_in('id', $ids);
            $this->db->set('expire', 'FLOOR (' . ee()->localize->now . ' + '.$period.' - ( ('.$id_end.' - id) / '.$id_count.' * ' . $period . ' ))', false);
            return $this->db->update('stash');
        }
        else
        {
            // invalidating a single item
            ee()->db->where('id', $ids[0]);
            $this->db->set('expire', ee()->localize->now);
            return $this->db->update('stash');
        }
    }

    /**
     * Prune expired keys
     *
     * @return boolean
     */
    public function prune_keys()
    {
        $query = $this->db->get_where('stash', array(
            'expire <'  => ee()->localize->now,
            'expire !=' => '0'
        ));

        // -------------------------------------
        // 'stash_prune' hook
        // -------------------------------------
        if (ee()->extensions->active_hook('stash_prune') === TRUE)
        {
            ee()->extensions->call('stash_prune', $query->result_array());
        }

        if ($query->num_rows() > 0)
        {
            if ($deleted = $this->delete_cache($query->result(), TRUE, 0, FALSE))
            {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Get a bundle id from the name
     *
     * @param string $bundle
     * @return bool|int
     */
    public function get_bundle_by_name(string $bundle)
    {
        $cache_key = $bundle;

        if ( ! isset(self::$bundle_ids[$cache_key]))
        {
            $result = $this->db->select('id')
                ->from('stash_bundles')
                ->where('bundle_name', $bundle)
                ->limit(1)
                ->get();

            if ($result->num_rows() === 1)
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
     * @return bool|string
     */
    public function get_bundle_by_id(int $bundle_id)
    {
        if ( ! $bundle_name = array_search($bundle_id, self::$bundle_ids) )
        {
            $result = $this->db->select('bundle_name')
                ->from('stash_bundles')
                ->where('id', $bundle_id)
                ->limit(1)
                ->get();

            if ($result->num_rows() === 1)
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
     * @param string $bundle_label
     * @return bool|int
     */
    public function insert_bundle(string $bundle, string $bundle_label = '')
    {
        $data = array(
            'bundle_name'   => $bundle,
            'bundle_label'  => $bundle_label
        );

        if ( $result = $this->db->insert('stash_bundles', $data) )
        {
            return $this->db->insert_id();
        }

        return FALSE;
    }

    /**
     * Check if there is at least one entry for the bundle in this site
     *
     * @param int $bundle_id
     * @param int $site_id
     * @return bool
     */
    public function bundle_entry_exists(int $bundle_id, int $site_id = 1)
    {
        $result = $this->db->select('id')
            ->from('stash')
            ->where('bundle_id', $bundle_id)
            ->where('site_id', $site_id)
            ->limit(1)
            ->get();

        if ($result->num_rows() === 1)
        {
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Count the number of entries for a given bundle
     *
     * @param int $bundle_id
     * @param int $site_id
     * @return int
     */
    public function bundle_entry_count(int $bundle_id, int $site_id = 1)
    {
        $result = $this->db->select('COUNT(id) AS row_count')
            ->from('stash')
            ->where('bundle_id', $bundle_id)
            ->where('site_id', $site_id)
            ->get();

        if ($result->num_rows() === 1)
        {
            return $result->row('row_count');
        }

        return 0;
    }


    /*
    ================================================================
    Static cache handler
    ================================================================
    */

    /**
     * Check if the variable can be cached as a static file and write it
     *
     * @param string|array $data
     * @return boolean
     */
    protected function write_static_cache($data)
    {
        if ( ! isset($data['bundle_id'])) {
            return FALSE;
        }

        // write to static file cache?
        if ($this->_can_static_cache($data['bundle_id']))
        {
            // extract the associated uri from the variable key
            $uri = $this->parse_uri_from_key($data['key_name']);

            // cache that mother
            return $this->_write_file($uri, $data['site_id'], $data['parameters']);
        }

        return FALSE;
    }

    /**
     * Delete the associated static file for a given variable
     *
     * @param string $key
     * @param integer $bundle_id
     * @param int $site_id
     * @return boolean
     */
    protected function delete_static_cache(string $key, int $bundle_id, int $site_id)
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
     * @param int $site_id
     * @param string|null $parameters
     * @return boolean
     */
    private function _write_file(string $uri, int $site_id, string $parameters = NULL)
    {
        ee()->load->helper('file');

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
     * @param int $site_id
     * @return boolean
     */
    private function _delete_file(string $uri = '/', int $site_id = 1)
    {
        if ($path = ($this->_path($uri, $site_id) && @unlink($this->_path($uri, $site_id) . $this->_static_file)))
        {
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Recursively delete directories and files in a static cache directory
     *
     * @param string $uri
     * @param int $site_id
     * @return boolean
     */
    private function _delete_dir(string $uri, int $site_id)
    {
        ee()->load->helper('file');

        if ( $path = ($this->_path($uri, $site_id) && delete_files($this->_path($uri, $site_id), true)))
        {
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Returns the path to the cache directory, if it exists
     *
     * @param string $uri
     * @param int $site_id
     * @return bool|string
     */
    private function _path(string $uri = '/', int $site_id = 1)
    {
        // Get parts
        $path = ee()->config->item('stash_static_basepath');

        // Check the supplied cache path exists
        if ( ! file_exists($path))
        {
            return FALSE;
        }

        // Blacklist of characters we don't want to allow as directory names in the cache
        $bad = ee()->config->item('stash_static_character_blacklist')
            ? (array) ee()->config->item('stash_static_character_blacklist')
            : array(LD, RD, '<', '>', ':', '"', '\\', '|', '*', '.');
        $new_uri = str_replace($bad, '', $uri);

        if ($uri !== $new_uri)
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
    private function _can_static_cache(int $bundle_id)
    {
        if ( ee()->config->item('stash_static_cache_enabled'))
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
    public function parse_uri_from_key(string $key)
    {
        $uri = explode(':', $key);
        return $uri[0] === $this->_index_key ? '' : $uri[0];
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

    /**
     * Prepare INSERT IGNORE BATCH SQL query
     *
     * @param string $table The table to insert into
     * @param Array $data Array in form of "Column" => "Value", ...
     * @return void
     */
    protected function insert_ignore_batch(string $table, array $data)
    {
        $_keys = array();
        $_prepared = array();

        foreach ($data as $row)
        {
            $_values = array();

            // static caching - save an index of the variable only?
            if (ee()->config->item('stash_static_cache_index')
                && $this->_can_static_cache($row['bundle_id']))
            {
                $row['parameters'] = ''; // remove variable content
            }

            foreach ($row as $col => $val)
            {
                // add key
                if ( ! in_array($col, $_keys) )
                {
                    $_keys[] = $col;
                }

                // add values
                $_values[] = $this->db->escape($val);
            }
            $_prepared[] = '(' . implode(',', $_values) . ')';
        }

        $this->db->query('INSERT IGNORE INTO '.$this->db->dbprefix.$table.' ('.implode(',',$_keys).') VALUES '.implode(',', array_values($_prepared)).';');
    }

    /**
     * Queue an insert for batch processing later
     *
     * @param string $table The table to insert into
     * @param string $cache_key Unique key identifying this variable
     * @param Array $data Array in form of "Column" => "Value", ...
     * @return boolean
     */
    protected function queue_insert(string $table, string $cache_key, array $data)
    {
        if ( ! isset(self::$queue->inserts[$table]))
        {
            self::$queue->inserts[$table] = array();
        }
        elseif( isset(self::$queue->inserts[$table][$cache_key]))
        {
            // insert already queued
            return FALSE;
        }

        self::$queue->inserts[$table][$cache_key] = $data;
        return TRUE;
    }

    /**
     * Queue an update for batch processing later
     *
     * @param string $table The table to insert into
     * @param string $cache_key Unique key identifying this variable
     * @param array $data Array in form of "Column" => "Value", ...
     * @return boolean
     */
    protected function queue_update(string $table, string $cache_key, array $data)
    {
        if ( ! isset(self::$queue->updates[$table]))
        {
            self::$queue->updates[$table] = array();
        }

        // overwrite any existing key, so that only the last update to same cached item actually runs
        self::$queue->updates[$table][$cache_key] = $data;
        return TRUE;
    }

    /**
     * Process queued queries
     *
     * @return void
     */
    public function process_queue()
    {
        // batch inserts - must run first
        foreach(self::$queue->inserts as $table => $data)
        {
            $this->insert_ignore_batch($table, $data);

            // write to static file cache
            foreach($data as $query)
            {
                $this->write_static_cache($query);
            }
        }

        // run each queued update in order
        if (count(self::$queue->updates) > 0)
        {
            // update keys
            foreach(self::$queue->updates as $table => $data)
            {
                foreach($data as $query)
                {
                    // required columns
                    $where = array(
                        'key_name'  => $query['key_name'],
                        'bundle_id' => $query['bundle_id'],
                        'site_id'   => $query['site_id']
                    );

                    if ( isset($query['session_id']))
                    {
                        $where += array('session_id' => $query['session_id']);
                        unset($query['session_id']);
                    }

                    // update db
                    $this->db->where($where);
                    $this->db->update($table, $query);

                    // update static file cache
                    $this->write_static_cache($query);
                }
            }
        }

        // reset the queue
        self::$queue->inserts = self::$queue->updates = array();
    }

    /**
     * Get the Queue object by reference
     *
     * @return stdClass
     */
    public function &get_queue()
    {
        return self::$queue;
    }

}

/* End of file stash_model.php */
/* Location: ./system/expressionengine/third_party/stash/models/stash_model.php */