<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Test fixture: Minimal model with no relations or behaviors.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 */
class Category extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'category';
    }

    public function rules(): array
    {
        return [
            ['name', 'required'],
            ['name', 'string', 'max' => 255],
            ['description', 'string'],
        ];
    }
}
