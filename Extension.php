<?php

namespace Bolt\Extension\xijia37\TaxonomyList;

use Bolt\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Bolt\Extensions\Snippets\Location as SnippetLocation;

/**
 * Class Extension
 * @package ImageUpload
 * @author  blockmurder (info@blockmurder.ch)
 */

class Extension extends \Bolt\BaseExtension
{


    /**
     * Info block for Sitemap Extension.
     */
    function info()
    {

        $data = array(
            'name' => "TaxonomyList",
            'description' => "An extension that adds a twig tag for Taxonomy Listings.",
            'author' => "Lodewijk Evers",
            'link' => "http://bolt.cm",
            'version' => "0.2",
            'required_bolt_version' => "1.6.5",
            'highest_bolt_version' => "1.6.5",
            'type' => "General",
            'first_releasedate' => "2014-06-06",
            'latest_releasedate' => "2014-06-19",
            'dependencies' => "",
            'priority' => 10
        );

        return $data;

    }

    /**
     * Initialize TaxonomyList. Called during bootstrap phase.
     */
    function initialize()
    {
        //if (empty($this->config['default_taxonomy'])) {
            //$this->config['default_taxonomy'] = 'tags';
        //}

        // Set up the routes for the sitemap..
        //$this->app->match("/taxonomies", array($this, 'taxonomies'));

        $this->addTwigFunction('taxonomylist', 'twigTaxonomyList');
    }


    /**
     * Return an array with items in a taxonomy
     */
    function twigTaxonomyList($name = false, $contenttype = false,  $params = false) {

        // if $name isn't set, use the one from the config.yml. Unless that's empty too, then use "tags".
        //if (empty($name)) {
            //if (!empty($this->config['default_taxonomy'])) {
                //$name = $this->config['default_taxonomy'];
            //} else {
                //$name = "tags";
            //}
        //}
        // \Dumper::dump($this->app['paths']);

        $taxonomy = $this->app['config']->get('taxonomy');

        if(array_key_exists($name, $taxonomy)) {
            $named = $taxonomy[$name];
            //if($params != false) {
                $named = $this->getFullTaxonomy($name, $contenttype, $taxonomy, $params);
            //}
            if(array_key_exists('options', $named)) {
                // \Dumper::dump($named);

                foreach($named['options'] as $slug => $item) {

                    if(is_array($item) && $item['name']) {
                        $catname = $taxonomy[$name]['options'][$slug];
                        //$catname = $item['name'];
                        $itemcount = $item['count'];
                    } else {
                        $catname = $item;
                        $itemcount = null;
                    }
                    $itemlink = $this->app['paths']['root'].$name .'/'.$slug;

                    $options[$slug] = array(
                        'slug' => $slug,
                        'name' => $catname,
                        'link' => $itemlink,
                        'count' => $itemcount,
                    );
                    if(isset($item['weight']) && $item['weight']>=0) {
                        $options[$slug]['weight'] = $item['weight'];
                        $options[$slug]['weightclass'] = $item['weightclass'];
                    }
                }
                // \Dumper::dump($named);
                // \Dumper::dump($options);
                $new_options = [];
                foreach( $taxonomy[$name]['options'] as $slug => $item ){

                    //var_dump($taxonomy[$name]['options']);die();
                    if ( array_key_exists($slug, $options) ){
                        $new_options[$slug] = $options[$slug];
                    }
                }
                //var_dump($options); die();
                //return $options;
                return $new_options;
            }
        }

        return null;

    }

    /**
     * Get the full taxonomy data from the database, count all occurences of a certain taxonomy name
     */
    function getFullTaxonomy($name = null, $contenttype = null, $taxonomy = null, $params = null) {

        if(array_key_exists($name, $taxonomy)) {
            $named = $taxonomy[$name];
            unset($named['options']);

            // default params
            $limit = $weighted = false;
            if(isset($params['limit']) && is_numeric($params['limit'])) {
                $limit = $params['limit'];
            }
            if(isset($params['weighted']) && $params['weighted']==true) {
                $weighted = true;
            }

            $condition = '';
            if(isset($params['filters'])) {
                foreach($params['filters'] as $field => $value) {
                    $condition .= sprintf(' AND c.%s = %s', $field, $value);
                }
            }

            $prefix = $this->app['config']->get('general/database/prefix', 'bolt_');
            $taxonomy_table_name    = $prefix . "taxonomy";
            $contenttype_table_name = $prefix . $contenttype;

            // type of sort depending on params
            if($weighted) {
                $sortorder = 'count DESC';
            } else {
                $sortorder = 'slug ASC';
            }

            $SQL_SELECT = sprintf('SELECT COUNT( t.name ) as count, t.slug, t.name FROM %s t', $taxonomy_table_name);
            $SQL_JOIN = sprintf(' LEFT JOIN %s c ON t.content_id = c.id', $contenttype_table_name);
            $SQL_WHERE = sprintf(' WHERE t.taxonomytype = "%s"', $name);

            if(isset($params['filters'])) {
                $i = 0;
                foreach($params['filters'] as $field => $value) {
                    $SQL_JOIN .= sprintf(' LEFT JOIN %s t_%s ON t_%s.content_id = c.id', $taxonomy_table_name, $i, $i);
                    $SQL_WHERE .= sprintf(' AND t_%s.taxonomytype = "%s" AND t_%s.slug = "%s"', $i, $field, $i, $value);
                }
            }

            $SQL_EXTRA_WHERE = ' AND c.status = "published" GROUP BY t.name ORDER BY t.name';

            // the normal query
            $query = $SQL_SELECT. $SQL_JOIN. $SQL_WHERE. $SQL_EXTRA_WHERE;
            //$query = sprintf(
                //"SELECT COUNT( t.name ) as count, t.slug, t.name
                //FROM %s t LEFT JOIN %s c
                //ON t.content_id = c.id
                //WHERE t.taxonomytype IN ('%s')
                //AND c.status = 'published'
                //%s
                //GROUP BY name ORDER BY %s",
                //$taxonomy_table_name,
                //$contenttype_table_name,
                //$name,
                //$condition,
                //$sortorder
            //);

            // append limit to query the parameter is set
            if($limit) {
                $query .= sprintf(' LIMIT 0, %d', $limit);
            }

            // fetch results from db
            $rows = $this->app['db']->executeQuery( $query )->fetchAll();;

            if($rows && ($weighted || $limit)) {
                // find the max / min for the results
                $named['maxcount'] = 0;
                $named['number_of_tags'] = count($named['options']);
                foreach($rows as $row) {
                    if($row['count']>=$named['maxcount']) {
                        $named['maxcount']= $row['count'];
                    }
                    if(!isset($named['mincount']) || $row['count']<=$named['mincount']) {
                        $named['mincount']= $row['count'];
                    }
                }

                $named['deltacount'] = $named['maxcount'] - $named['mincount'] + 1;
                $named['stepsize'] = $named['deltacount'] / 5;

                // return only rows with results
                $populatedrows = array();
                foreach($rows as $row) {
                    //var_dump($name);
                    //var_dump($row['slug']);
                    //var_dump($this->app['storage']->getContentByTaxonomy($name, $row['slug'], ['limit' => 1, 'page'=> 1]));
                    //if (! $this->app['storage']->getContentByTaxonomy($name, $row['slug'], ['limit' => 10, 'page'=> 1])) continue;

                    $row['weightpercent'] = ($row['count'] - $named['mincount']) / ($named['maxcount'] - $named['mincount']);
                    $row['weight'] = round($row['weightpercent'] * 100);

                    if($row['weight']<=20) {
                        $row['weightclass'] = 'xs';
                    } elseif($row['weight']<=40) {
                        $row['weightclass'] = 's';
                    } elseif($row['weight']<=60) {
                        $row['weightclass'] = 'm';
                    } elseif($row['weight']<=80) {
                        $row['weightclass'] = 'l';
                    } else {
                        $row['weightclass'] = 'xl';
                    }

                    $populatedrows[$row['slug']] = $row;
                }
                $named['options'] = $populatedrows;
                //die();
            } elseif($rows) {
                // return all rows - so add the count to all existing rows
                // weight is useless here
                foreach($rows as $row) {

                    $named['options'][$row['slug']] = $row;
                }
            }
            //var_dump($named);
            //die();

             //\Dumper::dump($named);
            //return null;
            return $named;
        }

        return null;
    }

    public function getName(){
        return 'TaxonomyList';
    }

}
