<?php
/**
 * @package      Gamification
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

jimport("Prism.init");
jimport("Gamification.init");

use Joomla\Utilities\ArrayHelper;

// No direct access
defined('_JEXEC') or die;

/**
 * This class provides functionality
 * for creating accounts used for storing
 * and managing gamification units.
 *
 * @package        Gamification
 * @subpackage     Plugins
 */
class plgUserGamification extends JPlugin
{
    /**
     * @var Joomla\Registry\Registry
     */
    public $params;

    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
     * Method is called after user data is stored in the database
     *
     * @param    array   $user    Holds the new user data.
     * @param    boolean $isNew   True if a new user is stored.
     * @param    boolean $success True if user was successfully stored in the database.
     * @param    string  $msg     Message.
     *
     * <code>
     *
     * </code>
     * @return    void
     * @since    1.6
     * @throws    Exception on error.
     */
    public function onUserAfterSave($user, $isNew, $success, $msg)
    {
        if ($isNew and $this->params->get("points_give", 0)) {
            $this->givePoints($user);
        }
    }

    /**
     * Add points to user account after registration.
     *
     * @param array $user
     */
    protected function givePoints($user)
    {
        $userId = ArrayHelper::getValue($user, 'id');
        $name   = ArrayHelper::getValue($user, 'name');

        $pointsTypesValues = $this->params->get("points_types", 0);

        // Parse point types
        $pointsTypes = array();
        if (!empty($pointsTypesValues)) {
            $pointsTypes = json_decode($pointsTypesValues, true);
        }

        if (!empty($pointsTypes)) {

            foreach ($pointsTypes as $pointsType) {

                $pointsType["value"] = (int)$pointsType["value"];

                // If there are no points for giving, continue for next one.
                if (!$pointsType["value"]) {
                    continue;
                }

                $points = Gamification\Points\Points::getInstance(JFactory::getDbo(), $pointsType["id"]);

                if ($points->getId() and $points->isPublished()) {

                    $keys = array(
                        "user_id"   => $userId,
                        "points_id" => $points->getId()
                    );

                    $userPoints = new Gamification\User\Points(JFactory::getDbo());
                    $userPoints->load($keys);

                    // Create an record if it does not exists.
                    if (!$userPoints->getId()) {
                        $userPoints->startCollectingPoints($keys);
                    }

                    // Increase user points.
                    $options = array(
                        "context" => "com_user.registration",
                    );

                    $userPoints->increase($pointsType["value"], $options);

                    // Send notification and store activity.

                    $params = JComponentHelper::getParams("com_gamification");

                    $options = array(
                        "user_id" => $userId,
                        "context_id" => $userId,
                        "app" => "gamification.points"
                    );

                    // Store activity.
                    $activityService = $params->get("integration_notifications");
                    if ($this->params->get("store_activity", 0) and!empty($activityService)) {
                        $options["social_platform"] = $activityService;

                        $points = htmlspecialchars($pointsType["value"] . " " . $userPoints->getTitle(), ENT_QUOTES, "UTF-8");
                        $message = JText::sprintf("PLG_USER_GAMIFICATION_ACTIVITY_AFTER_REGISTRATION", $name, $points);

                        Gamification\Helper::storeActivity($message, $options);
                    }

                    // Send notifications.
                    $integrationService = $params->get("integration_notifications");
                    if ($this->params->get("send_notification", 0) and !empty($integrationService)) {

                        $options["social_platform"] = $integrationService;

                        $message = JText::sprintf("PLG_USER_GAMIFICATION_NOTIFICATION_AFTER_REGISTRATION", $pointsType["value"], $userPoints->getTitle());
                        Gamification\Helper::sendNotification($message, $options);
                    }

                }
            }
        }
    }
}
