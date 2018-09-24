<?php

return array_merge(
    require Yii::getAlias('@yii/helpers/mimeTypes.php'), 
    [
    'md' => 'text/markdown',
    'js' => 'text/javascript',
    ]
);