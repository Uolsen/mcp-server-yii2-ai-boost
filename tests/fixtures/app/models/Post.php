<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Test fixture: Post model with multiple validators and relations.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property int $user_id
 * @property int|null $category_id
 * @property int $status
 * @property int|null $created_at
 */
class Post extends ActiveRecord
{
    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;

    public static function tableName(): string
    {
        return 'post';
    }

    public function rules(): array
    {
        return [
            [['title', 'body', 'user_id'], 'required'],
            ['title', 'string', 'min' => 3, 'max' => 255],
            ['body', 'string'],
            ['user_id', 'integer'],
            ['user_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            ['category_id', 'integer'],
            ['status', 'in', 'range' => [self::STATUS_DRAFT, self::STATUS_PUBLISHED]],
            ['status', 'default', 'value' => self::STATUS_DRAFT],
            [
                'title',
                'match',
                'pattern' => '/^[a-zA-Z0-9\s\-]+$/',
                'message' => 'Title can only contain letters, numbers, spaces and dashes',
            ],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'title' => 'Post Title',
            'body' => 'Content',
            'user_id' => 'Author',
            'category_id' => 'Category',
            'status' => 'Publication Status',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUser(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCategory(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }
}
