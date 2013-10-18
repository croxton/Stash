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

class Stash_upd {
    
    public $name    = STASH_NAME;
    public $version = STASH_VER;
    
    /**
     * Stash_upd
     * 
     * @access  public
     * @return  void
     */
    public function __construct()
    {
        $this->EE = get_instance();
    }
    
    /**
     * install
     * 
     * @access  public
     * @return  void
     */
    public function install()
    {   
        $sql = array();
        
        // install module 
        $this->EE->db->insert(
            'modules',
            array(
                'module_name' => $this->name,
                'module_version' => $this->version, 
                'has_cp_backend' => 'n',
                'has_publish_fields' => 'n'
            )
        );
        
        // stash table
        $sql[] = "
        CREATE TABLE `{$this->EE->db->dbprefix}stash` (
          `id` int(11) unsigned NOT NULL auto_increment,
          `site_id` int(4) unsigned NOT NULL default '1',
          `session_id` varchar(40) default NULL,
          `bundle_id` int(11) unsigned NOT NULL default '1',
          `key_name` varchar(255) NOT NULL,
          `key_label` varchar(255) default NULL,
          `created` int(10) unsigned NOT NULL,
          `expire` int(10) unsigned NOT NULL default '0',   
          `parameters` MEDIUMTEXT,
          PRIMARY KEY  (`id`),
          KEY `bundle_id` (`bundle_id`),
          KEY `key_session` (`key_name`,`session_id`),
          KEY `key_name` (`key_name`),
          KEY `site_id` (`site_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ";

        // stash_bundles table
        $sql[] = "
        CREATE TABLE `{$this->EE->db->dbprefix}stash_bundles` (
          `id` int(11) unsigned NOT NULL auto_increment,
          `bundle_name` varchar(255) NOT NULL,
          `bundle_label` varchar(255) default NULL,
          `is_locked` tinyint(1) unsigned NOT NULL default '0',
          PRIMARY KEY  (`id`),
          KEY `bundle` (`bundle_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ";

        // foreign key constraints
        $sql[] = "
        ALTER TABLE `{$this->EE->db->dbprefix}stash`
        ADD CONSTRAINT `{$this->EE->db->dbprefix}stash_fk` FOREIGN KEY (`bundle_id`) REFERENCES `{$this->EE->db->dbprefix}stash_bundles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
        ";
        
        // default bundles
        $sql[] = "INSERT INTO `{$this->EE->db->dbprefix}stash_bundles` VALUES(1, 'default', 'Default', 1);";
        $sql[] = "INSERT INTO `{$this->EE->db->dbprefix}stash_bundles` VALUES(2, 'templates', 'Templates', 1);";
        $sql[] = "INSERT INTO `{$this->EE->db->dbprefix}stash_bundles` VALUES(3, 'static', 'Static', 1);";
        
        // run the queries one by one
        foreach ($sql as $query)
        {
            $this->EE->db->query($query);
        }

        return TRUE;
    }
    
    /**
     * uninstall
     * 
     * @access  public
     * @return  void
     */
    public function uninstall()
    {
        $query = $this->EE->db->get_where('modules', array('module_name' => 'Stash'));
        
        if ($query->row('module_id'))
        {
            $this->EE->db->delete('module_member_groups', array('module_id' => $query->row('module_id')));
        }

        $this->EE->db->delete('modules', array('module_name' => 'Stash'));
        
        $this->EE->load->dbforge();
        $this->EE->dbforge->drop_table('stash');
        $this->EE->dbforge->drop_table('stash_bundles');

        return TRUE;
    }
    
    /**
     * update
     * 
     * @access  public
     * @param   mixed $current = ''
     * @return  void
     */
    public function update($current = '')
    {
        if ($current == '' OR version_compare($current, $this->version) === 0)
        {
            // up to date
            return FALSE;
        }

        // Update to 2.3.7
        if (version_compare($current, '2.3.7', '<'))
        {
            $sql = array();

            // increase variable max key and parameter sizes
            $sql[] = "ALTER TABLE `{$this->EE->db->dbprefix}stash` CHANGE `key_name` `key_name` VARCHAR(255) NOT NULL";
            $sql[] = "ALTER TABLE `{$this->EE->db->dbprefix}stash` CHANGE `key_label` `key_label` VARCHAR(255) NOT NULL";
            $sql[] = "ALTER TABLE `{$this->EE->db->dbprefix}stash` CHANGE `parameters` `parameters` MEDIUMTEXT DEFAULT NULL";

            // alter the bundle table
            $sql[] = "ALTER TABLE `{$this->EE->db->dbprefix}stash_bundles` CHANGE `bundle_name` `bundle_name` VARCHAR(255) NOT NULL";
            $sql[] = "ALTER TABLE `{$this->EE->db->dbprefix}stash_bundles` CHANGE `bundle_label` `bundle_label` VARCHAR(255) NOT NULL";
            $sql[] = "ALTER TABLE `{$this->EE->db->dbprefix}stash_bundles` DROP `site_id`";
            $sql[] = "ALTER TABLE `{$this->EE->db->dbprefix}stash_bundles` ADD `is_locked` TINYINT(1) NOT NULL default '0'";

            // delete all bundle records - note that this will cascade and delete variables
            $sql[] = "DELETE FROM `{$this->EE->db->dbprefix}stash_bundles` WHERE 1;";

            // add the 'default' and 'template' bundles, mark as locked
            $sql[] = "INSERT INTO `{$this->EE->db->dbprefix}stash_bundles` VALUES(1, 'default', 'Default', 1);";
            $sql[] = "INSERT INTO `{$this->EE->db->dbprefix}stash_bundles` VALUES(2, 'templates', 'Templates', 1);";
            $sql[] = "INSERT INTO `{$this->EE->db->dbprefix}stash_bundles` VALUES(3, 'static', 'Static', 1);";

            foreach ($sql as $query)
            {
                $this->EE->db->query($query);
            }
        }

        // update version number
        return TRUE;
        
    }
}

/* End of file upd.stash.php */
/* Location: ./system/expressionengine/third_party/stash/upd.stash.php */