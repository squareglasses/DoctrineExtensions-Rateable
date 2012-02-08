<?php

/*
 * This file is part of the Doctrine Extensions Rateable package.
 * (c) 2011 Fabien Pennequin <fabien@pennequin.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DoctrineExtensions\Rateable;

/**
 * Rateable is the interface that rateable resource classes must implement.
 *
 * @author Fabien Pennequin <fabien@pennequin.me>
 * @author Bat <contact@graindeweb.fr>
 */
interface Rateable
{
    /**
     * Get rateable identifier, typically id (getId())
     * return mixed
     */
    function getRateableId();
    
    /**
     * Get rateable Type, typically class name
     * @return string
     */
    function getRateableType();

    
    /**
     * Return number of votes
     * @return integer
     */
    function getRatingVotes();
    
    /**
     * Set number of votes
     * @param integer $number
     */
    function setRatingVotes($number);

    
    /**
     * Get rateable object score (total)
     * @return integer
     */
    function getRatingTotal();
    
    /**
     * Set rateable object score (total)
     * @param integer $number
     */
    function setRatingTotal($number);
    
    
    /**
     * Compute the average score of a resource
     * @return integer
     */
    function getAverageScore();
}
