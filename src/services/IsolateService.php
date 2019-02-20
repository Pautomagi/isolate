<?php
/**
 * Isolate plugin for Craft CMS 3.x
 *
 * Force users to only access a subset of your entries
 *
 * @link      https://trendyminds.com
 * @copyright Copyright (c) 2019 TrendyMinds
 */

namespace trendyminds\isolate\services;

use trendyminds\isolate\Isolate;
use trendyminds\isolate\records\IsolateRecord;
use trendyminds\isolate\assetbundles\Isolate\IsolateAsset;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\elements\Entry;
use craft\db\Query;

/**
 * @author    TrendyMinds
 * @package   Isolate
 * @since     1.0.0
 */
class IsolateService extends Component
{
    // Public Methods
    // =========================================================================

    /*
     * @return mixed
     */
    public function getUsers()
    {
        $users = User::find()
            ->admin(false)
            ->can("accessCp")
            ->all();

        return $users;
    }

    /*
     * @return mixed
     */
    public function getUser(int $id)
    {
        return User::find()
            ->id($id)
            ->one();
    }

    /*
     * @return mixed
     */
    public function getUserSections(int $userId)
    {
        $sections = [];

        // Get all editable sections
        $allSections = Craft::$app->sections->getEditableSections();

        // Filter out the sections that are "singles"
        $allSections = array_filter($allSections, function($section) {
            return $section->type !== "single";
        });

        // Return a new array that outputs the section name and if this user can edit the entries in that content type
        foreach ($allSections as $section) {
            $sections[] = [
                "name" => $section->name,
                "id" => $section->id,
                "handle" => $section->handle,
                "canEdit" => Craft::$app->getUserPermissions()->doesUserHavePermission($userId, "editEntries:{$section->uid}")
            ];
        }

        return $sections;
    }

    /*
     * @return mixed
     */
    public function getUserEntries(int $userId, int $sectionId = null)
    {
        $query = new Query();

        $records = $query
            ->select(["iso.*", "ent.sectionId"])
            ->from("{{%isolate_permissions}} iso")
            ->leftJoin("{{%entries}} ent", "ent.id=iso.entryId")
            ->filterWhere([
                "iso.userId" => $userId,
                "ent.sectionId" => $sectionId
            ])
            ->all();

        return $records;
    }

    /*
     * @return mixed
     */
    public function savePermissions(int $userId, array $entries)
    {
        /**
         * If a user has been assigned permissions, enable Isolate automatically to make the workflow contained in one place
         */
        if (count($entries) > 0) {
            $usersPermissions = Craft::$app->userPermissions->getPermissionsByUserId($userId);
            $usersPermissions[] = "accessplugin-isolate";

            Craft::$app->userPermissions->saveUserPermissions($userId, $usersPermissions);
        }

        /**
         * If a user has no assigned permissions disable their access to Isolate
         */
        if (count($entries) === 0) {
            $usersPermissions = Craft::$app->userPermissions->getPermissionsByUserId($userId);

            $usersPermissions = array_filter($usersPermissions, function($permission) {
                return $permission !== "accessplugin-isolate";
            });

            Craft::$app->userPermissions->saveUserPermissions($userId, $usersPermissions);
        }

        /**
         * Remove entries that were de-selected
         */
        $existingEntries = IsolateRecord::findAll([
            "userId" => $userId
        ]);

        $existingEntries = array_map(function($permission) {
            return $permission->entryId;
        }, $existingEntries);

        $entriesToRemove = array_values(array_diff($existingEntries, $entries));

        foreach ($entriesToRemove as $entryId)
        {
            $record = IsolateRecord::findOne([
                "entryId" => $entryId
            ]);

            $record->delete();
        }

        /**
         * Add entries that are new selections
         */
        $entriesToAdd = array_values(array_diff($entries, $existingEntries));

        foreach ($entriesToAdd as $entryId)
        {
            $record = new IsolateRecord;

            $record->setAttribute('userId', $userId);
            $record->setAttribute('entryId', $entryId);

            $record->save();
        }
    }

    public function includeIsolatedAssets()
    {
        Craft::$app->getView()->registerAssetBundle(IsolateAsset::class);
    }
}
