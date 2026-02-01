<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;

/**
 * Test fixture: User model with relations, behaviors, scenarios.
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string $password_hash
 * @property int $status
 * @property int $created_at
 * @property int $updated_at
 */
class User extends ActiveRecord
{
    public const STATUS_ACTIVE = 10;
    public const STATUS_INACTIVE = 0;

    public static function tableName(): string
    {
        return 'user';
    }

    public function behaviors(): array
    {
        return [
            'timestamp' => [
                'class' => TimestampBehavior::class,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['username', 'email'], 'required'],
            [['username', 'email'], 'string', 'max' => 255],
            ['email', 'email'],
            ['username', 'unique'],
            ['status', 'integer'],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_INACTIVE]],
        ];
    }

    public function scenarios(): array
    {
        $scenarios = parent::scenarios();
        $scenarios['register'] = ['username', 'email', 'password_hash'];
        $scenarios['update'] = ['username', 'email', 'status'];
        return $scenarios;
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'username' => 'Username',
            'email' => 'Email Address',
            'status' => 'Status',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'username' => 'Choose a unique username',
            'email' => 'Your primary email address',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getPosts(): \yii\db\ActiveQuery
    {
        return $this->hasMany(Post::class, ['user_id' => 'id']);
    }

    public function fields(): array
    {
        $fields = parent::fields();
        unset($fields['password_hash']);
        return $fields;
    }

    public function extraFields(): array
    {
        return ['posts'];
    }
}
