//画像アップロード時にデフォルトのリサイズ機能使わない
add_filter( 'intermediate_image_sizes_advanced', 'hack_intermediate_image_sizes_advanced' );
add_filter( 'wp_calculate_image_srcset_meta', '__return_null' );
function hack_intermediate_image_sizes_advanced( $sizes ) {
	 $sizes = array();  # 空にする
    return $sizes;
}
//upload ディレクトリ
function wpUploadDir(){
	$uploadServerPath = wp_upload_dir()['basedir'];
	$uploadUrlPath = wp_upload_dir()['baseurl'];
	return [$uploadServerPath,$uploadUrlPath];
}
//srcset(デバイスピクセル分のイメージルート)作る関数
function createSrcset($imgRoot){
	$srcset = '';//srcsetが入る変数
	global $imgDevicePixel,$imgDevicePixelNameFormat;
	$ext = pathinfo($imgRoot, PATHINFO_EXTENSION);//拡張子
	$serverRoot = $_SERVER['DOCUMENT_ROOT'];//サーバールート
	$urlRoot = empty($_SERVER['HTTPS']) ? 'http://' : 'https://' . $_SERVER['HTTP_HOST'];//URLルート
	for($i = 0; $i < $imgDevicePixel; $i++){
		$fileName = str_replace('.'.$ext,'',$imgRoot);//拡張子外したやつ
		if(0 < $i){
			$fileName = $fileName;
			$imgPlusName = sprintf($imgDevicePixelNameFormat, $i+1);//@%dx
			preg_match("/(@)(.*)/is", $imgPlusName, $pixcel);//%dx
			$pixcel = ' '.$pixcel[2];  
		}
		$plusFile = $fileName.$imgPlusName.'.'.$ext;//変更したファイル名
		$fileRootServer = str_replace($urlRoot,$serverRoot,$plusFile);//サーバールートに変換（ファイルが有るか確認するため）
		if(0 < $i) $plusFile = ', '.$plusFile;
		if(file_exists($fileRootServer)) $srcset .= $plusFile.$pixcel;
	}
	return 'srcset="'.$srcset.'"';
 }
 //source mediaつくる関数
 function sourceMedia($maxWidths,$defaultFileName,$alt){
	$ext = '.'.pathinfo($defaultFileName, PATHINFO_EXTENSION);//拡張子
	$fileName = str_replace($ext,'',$defaultFileName);//拡張子外したやつ
	$sourceMediaAll = '';//すべてのsource media
	foreach($maxWidths as $maxWidth){
		$sourceMedia = '<source media="(max-width: '.$maxWidth.'px)" %s>';
		$sprintf = createSrcset($fileName.'_'.$maxWidth.$ext);
		$sourceMediaAll .= sprintf($sourceMedia,$sprintf);
	}
	$picture = '<picture>'.$sourceMediaAll.'<img src="'.$defaultFileName.'" '.createSrcset($defaultFileName).' class="responsive-img" alt="'.$alt.'"/> </picture>';
	return $picture;
}
//エンコードした画像名返す
function imgUrl($attachment_id){
	$imgMetadata = wp_get_attachment_metadata( $attachment_id  );
	wpUploadDir()[0] = wp_upload_dir()['basedir'];
	//upload ディクレトリ以降の画像ディレクトリ取得
	$imgDirectory = $imgMetadata['file'];
	if(!$imgDirectory) return;
	//画像データが入っているサーバー内の絶対パス
	$file = path_join(wpUploadDir()[0],$imgDirectory);
	$_filenameArr = explode( '.', $file );
	$imgUrl = urlencode($_filenameArr[2]);
	return $imgUrl;
}
//////////////////////////////////////////////////
//データベース
//////////////////////////////////////////////////
//データベースを扱えるように
require_once( dirname(dirname(dirname(dirname( __FILE__ )))) . '/wp-load.php' );
//////////////////////////////////////////////////
//画像のデータベース
//////////////////////////////////////////////////
function imgUseDatabase($allImgLists,$postId,$sizeName,$postImageId){
	//画像データをデータベースに登録する準備
	foreach($allImgLists as $key => $imgRoot){
		$devicePixelKye = array_keys($imgRoot);
		$imgRootCount = count($imgRoot);
		for($i = 0; $i < $imgRootCount; $i++){
			$fileRootUrl = $imgRoot[$devicePixelKye[$i]];
			$fileRootServer = str_replace(wpUploadDir()[1],wpUploadDir()[0],$fileRootUrl);
			if(file_exists($fileRootServer)){
				//ファイルあったらエンコード
				$allImgLists[$key][$devicePixelKye[$i]] = urlencode($fileRootUrl);
			}else{
				//ファイルなかったら削除
				unset($allImgLists[$key][$devicePixelKye[$i]]);
			}
		}
	}
	//データベースにウィンドウサイズとデバイスピクセル、ファイルルートが入った配列を追加もしくは更新
	//データベース使う
	global $wpdb;
	//データベースに画像データがあるか確認
	$tablename =  $wpdb->prefix . "img_datas";
	$allImgListsJsonEncode = json_encode($allImgLists);
	$query = $wpdb->prepare( 
		"
		SELECT * 
		FROM $tablename
		WHERE 
		post_id = %d AND 
		img_name LIKE %s
		",
		$postId,
		'%' . $sizeName . '%'
	);
	$results = $wpdb->get_results( $query );
	//データベースからの返り値データ
	$resultsDateFirest = $results[0];
	$results[0] = (array) $resultsDateFirest;
	//img_name $sizeNameのデータなかったら追加あったら更新
	if(!$resultsDateFirest&&!is_null($allImgLists)){
		$wpdb->insert(
			$tablename,
			array(
				'id' => $postImageId,
				'post_id' => $postId,
				'img_name' => $sizeName,
				'img_data' => $allImgListsJsonEncode,
			),
			array(
				'%d',
				'%d',
				'%s',
				'%s'
			)
		);
	}elseif($results[0]['img_data'] === $allImgListsJsonEncode){
	}elseif(!is_null($allImgLists)){
		$wpdb->update(
			$tablename,
			array(
				'id' => $postImageId,
				'img_data' => $allImgListsJsonEncode,
			),
			array(
				'post_id' => $postId,
				'img_name' => $sizeName,
			),
			array(
				'%d',
				'%s'
			),
			array(
				'%d',
				'%s'
			)
		);
	}
} 

//////////////////////////////////////////////////
//picure タグ用リサイズ
//////////////////////////////////////////////////
//リサイズするウィンドウサイズと画像サイズ設定配列
$resizeImgs = [
	'画像名名前(例 thumbnail)' => [
		'width' => [ #横幅の設定
			画面横幅のサイズmax-widht(例`` 1023) => 152,
			'srcset' => 275, #(例 画面横幅のサイズ1024px以上)
		],
		'height' => [#高さ設定
			1023 => null,
			'srcset' => null,
		],
		'crop' => false,#切り抜き設定
	],
];
//指定のキー順にソートする関数(ソートするデータ,ソートする順のキー)
function sortByKeys($sorterDatas, $sorterKeys){
	$sorter = array_flip($sorterKeys);
	uksort($sorterDatas, function($a, $b) use ($sorter) {
		$aSortOrder = isset($sorter[$a]) ? $sorter[$a] : -1;
		$bSortOrder = isset($sorter[$b]) ? $sorter[$b] : -1;
		return $aSortOrder - $bSortOrder;
	});
	return $sorterDatas;
}
//デバイスピクセル対応用の関数
function addImageSizeRoop($sizeName, $imageSizeXs, $imageSizeYs,$breakPointDatas, bool $resizeCrop, int $createImgLoopCount, int $createImgLoopAdditional,$countOfMediaQuery,$imgDevicePixelNameFormat){
	global $imgDevicePixel;
	$count = $imgDevicePixel;//最初に作るデバイスピクセル（対応する中で一番の大きい解像度）
	$heightIndex = 0;//高さのindex
	//add_image_size()作成
	if (!function_exists('createAddImageSize')) {
		function createAddImageSize($value, $count,$heightIndex,$sizeName,$imageSizeYs,$breakPointDatas,$resizeCrop,$imgDevicePixelNameFormat) {
			//倍数の画像場合は倍に
			$imgDevicePixelNameFormat = sprintf($imgDevicePixelNameFormat, $count);
			 //end イメージ名ごとにループ	 
			$imgMagnificationName = $count > 1 ? $imgDevicePixelNameFormat: '';
			$imageNameX = $count > 1 ? $sizeName.'_'.$breakPointDatas[$heightIndex].$imgMagnificationName : $sizeName.'_'.$breakPointDatas[$heightIndex];
			$imageSizeXsX = $count > 1 ? $value * $count : $value;	
			if($count > 1){//倍数の画像
				$imageSizeYsX = $imageSizeYs[$heightIndex] ? $imageSizeYs[$heightIndex]*$count : 0;//高さがある場合高さを倍に 
			}elseif($count === 1){//１倍の画像
				$imageSizeYsX = $imageSizeYs[$heightIndex] ? $imageSizeYs[$heightIndex] : 0;//高さがある場合高さを倍に 
			}
			$returuImgDatas = [$imageNameX,['width'=>$imageSizeXsX,'height'=>$imageSizeYsX,'crop'=>$resizeCrop]];//[画像名,['widht'=>横幅,'height'=>高さ,'crop'=>切り抜き設定]]
			return $returuImgDatas;
		}
	}
	$array_filled = array_fill(0, $createImgLoopCount, $imageSizeXs);
	array_walk_recursive($array_filled, function($value) use (&$count,&$heightIndex,$sizeName,$imageSizeYs,$breakPointDatas,$resizeCrop,$countOfMediaQuery,&$imgDatas,$imgDevicePixelNameFormat) {
		$returuImgDatas = createAddImageSize($value, $count, $heightIndex, $sizeName,$imageSizeYs,$breakPointDatas,$resizeCrop,$imgDevicePixelNameFormat);
		$heightIndex ++;
		if($heightIndex % $countOfMediaQuery === 0){
			$count--;
			$heightIndex = 0;
		}
		$imgDatas[$returuImgDatas[0]] = $returuImgDatas[1];
	});
	if($createImgLoopAdditional > 0){
		for($i = 0; $i < $createImgLoopAdditional; $i++){
			$returuImgDatas = createAddImageSize($imageSizeXs[$i], $count,$i,$sizeName,$imageSizeYs,$breakPointDatas,$resizeCrop,$imgDevicePixelNameFormat);
			$imgDatas[$returuImgDatas[0]] = $returuImgDatas[1];
		}
	}	
	return $imgDatas;
}
/*リサイズする画像のファイルルートリストを作る関数
(画像id,使う画像のデータ,アップロードファイル出力するか,metaデータ出力するか,$crateImgLists(作成する画像のリスト)作るか),upload ディクレトリ以降の画像ディレクトリ出力すか*/
function createAllImgLists($postImageId,$imgDatas,$createFile=false,$createImgMetadata =false,$createCrateImgLists = false,$outputUploadPath = false){
	//画像のデータ取得
	$imgMetadata = wp_get_attachment_metadata( $postImageId );
	//upload ディクレトリ以降の画像ディレクトリ取得
	$imgDirectory = $imgMetadata['file'];
	if(!$imgDirectory) return;
	//画像データが入っているサーバー内の絶対パス
	$file = path_join(wpUploadDir()[0],$imgDirectory);
	//画像のデータ必要な部分取得
	//ファイル名取得(拡張子付き)
	$name = $imgDirectory;
	//拡張子を取得
	$_filenameArr = explode( '.', $name );
	$ext = $_filenameArr[1];
	//ファイル名取得(拡張子なし)
	$name = $_filenameArr[0];
	/*空配列に(イメージサイズをすべて初期化)
	画像のメタデータを残さず上書きしてしまいたいから
	他のプラグインによって追加されたメタデータに邪魔されたくないから*/
	$imgMetadata = array();
	//画像の幅と高さ取得
	$imagesize = getimagesize($file);
	$width = $imagesize[0];
	$height = $imagesize[1];
	//縦横比を維持した縮小サイズを設定（小サイズの画像を表示するための高さ/幅）
	list($uwidth, $uheight) = wp_constrain_dimensions($width, $height, 254, 0);
	$imgMetadata['hwstring_small'] = "height='$uheight' width='$uwidth'";
	//uploadディレクトリからの相対パスを設定
	$imgMetadata['file'] = $imgDirectory;
	$connectTxt = '-'; //画像名とadd_image_sizeの画像名をつなぐ接続の文字
	//画像名のリスト
	$imageSizeNames = array_keys($imgDatas);//add_image_sizeの画像名
	//画像未作成のリスト
	$crateImgLists = array();
	//画像あるか確認
	foreach($imageSizeNames as $imageSizeName){
		//リサイズした画像の後ろにつける名前
		$connectImageSizeName = $connectTxt.$imageSizeName;
		//リサイズした画像を再リサイズしなようにする設定
		//画像のフルパス
		$fileFullRoot = '%s/'.$name.$connectImageSizeName.'.'.$ext;
		$fileFullRootServer =  sprintf($fileFullRoot, wpUploadDir()[0]);
		$fileFullRootUrl = sprintf($fileFullRoot, wpUploadDir()[1]);
		//すべての画像リストに追加
		preg_match('/(@)(.*)/', $imageSizeName, $devicePixel);
		preg_match('/_([^_@]+)@/', $imageSizeName, $windowWidth);
		//デバイスサイズが無い画像＝1xの標準なのでsrcsetに追加
		if(!$windowWidth) $windowWidth[1] = 'srcset';
		$allImgLists[$windowWidth[1]][$devicePixel[2]] =  $fileFullRootUrl;
		//画像がなければ画像作成リストに追加
		if(!file_exists($fileFullRootServer) && $createCrateImgLists) $crateImgLists[$imageSizeName]= $fileFullRootServer;
	}
	$fileRoot = $createFile ? $file : '';
	$imgMetadata = $createImgMetadata ? $imgMetadata : '';
	$filePathOfupload = $outputUploadPath ? $imgDirectory : ''; 
	return [$allImgLists,$width,$height,$fileRoot,$imgMetadata,$crateImgLists,$filePathOfupload];
}
/*画像リサイズ
(横幅,高さ,切り抜き設定,リサイズ後の名前,アップロードファイルのある場所,サイズ名)*/
function hack_image_make_intermediate_size( $width, $height, $crop = false, $fileRoot, $file ,$size = "") {
	//横幅もしくは高さがある場合
	if ( !$width || !$height ) return;
	//コアファイルを触らずにサムネイルのクオリティ値を変える
	$resized_img = wp_get_image_editor( $file );
	$destfilename = $file;
	if ( ! is_wp_error( $resized_img) ) {
		$destfilename = $fileRoot;
		$resized_img->set_quality( 90 );
		$resized_img->resize( $width, $height, $crop );
		// リサイズして保存
		$resized_img->save( $destfilename );
	}
	$resized_file = $destfilename;
	//渡された変数が WordPress Error であるかチェックします
	if ( !is_wp_error( $resized_file ) && $resized_file && $info = getimagesize( $resized_file ) ) {
		//他のプラグインなどでimage_make_intermediate_sizeにフィルタがかけてあるなら、ちゃんとそれを通す
		$resized_file = apply_filters('image_make_intermediate_size', $resized_file);
		return array(
			'file' => wp_basename( $resized_file ),//ベース名（パスの最後にある名前の部分）を取得する
			'width' => $info[0],
			'height' => $info[1],
			'size' => $size
		);
	}	
}
$imgDevicePixel = 3;//対応するデバイスピクセル比　お好みで設定
$imgDevicePixelNameFormat = '@%dx';//2x 3xの画像の名前
//リサイズ
function resizeImg($postId,$postImageId,$sizeName){
	global $resizeImgs,$imgDevicePixel,$imgDevicePixelNameFormat;
	$sizeData = $resizeImgs[$sizeName];
	if(!$sizeData)return;
	//各ウィンドウサイズのadd_image_sizeを作る関数(プラグインにする際はsrcsetのほかに設定した枚数分-1のウィンドウサイズが設定できるように)
	$numberOfImagesCreated = 4;//一箇所に設定する画像の最大枚数　お好みで設定
	$resizeImgsWidthDatas = $sizeData['width'];
	$resizeImgsHeightDatas = $sizeData['height'];
	$resizeCrop = $sizeData['crop'];//切り抜き設定
	//width部分
	arsort($resizeImgsWidthDatas);//値が大きい順にソート
	$resizeImgsWidths = array_keys($resizeImgsWidthDatas);	//キー取得
	//$resizeImgsすべてのwidthの配列をつくる
	//heigtをwidthの値が大きい順にソート
	$resizeImgsHeightDatas = sortByKeys($resizeImgsHeightDatas, $resizeImgsWidths);	
	$createImgLoopCount = 0;//イメージ作成のためのループ回数
	$createImgLoopAdditional = 0;//イメージ作成のための追加分
	$countOfMediaQuery = count($resizeImgsWidthDatas);//メディアクエリの数
	//最大作成可能枚数
	$MaxCanCreated = $countOfMediaQuery * $imgDevicePixel;
	//作られない画像がある場合エラー表示
	if($countOfMediaQuery > $numberOfImagesCreated){
		trigger_error('$windowSizeWidthDatasの配列の個数を$numberOfImagesCreated以下にしてください。作成できない画像があります。',E_USER_ERROR);//エラー
	}
	//一箇所に設定する画像の最大枚数が最大作成可能枚数より多く設定されていた場合
	if($numberOfImagesCreated > $MaxCanCreated){
		$numberOfImagesCreated = $MaxCanCreated;//一箇所に設定する画像の最大枚数を最大作成可能枚数に修正
	}//$createImgLoopCount代入
	if($numberOfImagesCreated < $imgDevicePixel){//一箇所に設定する画像の最大枚数が対応するデバイスピクセル比より少なく設定されていた場合
		$createImgLoopCount = $numberOfImagesCreated;//一箇所に設定する画像の最大枚数を代入
}elseif($numberOfImagesCreated / $countOfMediaQuery){//それ以外
		$createImgLoopCount = floor($numberOfImagesCreated / $countOfMediaQuery);//割った数(切り捨て)を代入
	}
	//$createImgLoopAdditional代入
	if($numberOfImagesCreated % $countOfMediaQuery !== 0){
		$createImgLoopAdditional = $numberOfImagesCreated % $countOfMediaQuery;//追加分=余りを代入
	}
	//breakpoint部分
	$breakPointDatas = array_values($resizeImgsWidths);
	//width部分キー削除
	$resizeImgsWidthDatas = array_values($resizeImgsWidthDatas);
	//height部分キー削除
	$resizeImgsHeightDatas = array_values($resizeImgsHeightDatas);
	//デバイスピクセル対応
	$imgDatas = addImageSizeRoop($sizeName,$resizeImgsWidthDatas,$resizeImgsHeightDatas,$breakPointDatas,$resizeCrop,$createImgLoopCount,$createImgLoopAdditional,$countOfMediaQuery,$imgDevicePixelNameFormat);	//(イメージの名前, 横幅,　高さ, ブレイクポイント,切り抜き設定, ループ回数, 追加分作成分,メディアクエリ,デバイスピクセルの名前フォーマット)
	//リサイズ画像のファイルルートリストを作る
	$createAllImgLists = createAllImgLists($postImageId,$imgDatas,true,true,true,true);
	$crateImgLists = $createAllImgLists[5];
	$crateImgListNames = array_keys($crateImgLists);
	$allImgLists = $createAllImgLists[0];
	$width = $createAllImgLists[1];
	$height = $createAllImgLists[2];
	$metadata = $createAllImgLists[4];	
	imgUseDatabase($allImgLists,$postId,$sizeName,$postImageId);
	//ない画像があればつくっていく
	if(!$crateImgLists)return;
	foreach($crateImgListNames as $crateImgListName){
		$imgDatasWidth = intval( $imgDatas[$crateImgListName]['width'] );
		$imgDatasHeighth = intval( $imgDatas[$crateImgListName]['height'] );
		$imgDatasCrop = $imgDatas[$crateImgListName]['crop'];
		$fileFullRoot = $crateImgLists[$crateImgListName];
		//アップロード画像が指定サイズ以下ならリサイズしない
		if($width < $imgDatasWidth || $height < $imgDatasHeighth) continue;
		//['widht'=>横幅,'height'=>高さ,'crop'=>切り抜き設定]追加
		$sizes[$crateImgListName] = array( 'width' => '', 'height' => '', 'crop' => FALSE );
		//画像のメタデータに追加するデータを作成
		//ファイルのルートパス
		$sizes[$crateImgListName]['root'] = $fileFullRoot ? $fileFullRoot : null;
		$sizes[$crateImgListName]['width'] = $imgDatasWidth ? $imgDatasWidth : get_option( "{$crateImgListName}_size_w" );
		//height
		$sizes[$crateImgListName]['height'] = $imgDatasHeighth ? $imgDatasHeighth : 'auto';
		//crop
		$sizes[$crateImgListName]['crop'] = isset($imgDatasCrop) ? intval($imgDatasCrop) : get_option( "{$crateImgListName}_crop" );
	}
	if(!$sizes) return;
	//メタデータに追加していく
	foreach ($sizes as $size => $size_data ) {
	//アップロード画像は、リサイズ設定が追加されたときのためにリネームせず取っておく
	//リサイズ
	$resized = hack_image_make_intermediate_size($size_data['width'], $size_data['height'], $size_data['crop'],$size_data['root'], $createAllImgLists[3], $size);
	if ( $resized ){
		$metadata['sizes'][$size] = $resized;//メタデータのsizesに追加
	}
	//require_once ABSPATH . '/wp-admin/includes/image.php';
	$image_meta = wp_read_image_metadata( $createAllImgLists[3] );//画像ファイルの拡張メタデータ
	//画像ファイルの拡張メタデータがあれば拡張メタデータ追加
	if ( $image_meta ){
		$metadata['image_meta'] = $image_meta;
	}
	}
	wp_update_attachment_metadata( $postImageId, $metadata );//画像にメタデータ生成
	imgUseDatabase($allImgLists,$postId,$sizeName,$postImageId);
}
//新規投稿・更新サムネイル画像リサイズ
function crateResizeThumbnail($postId){
	$post_thumbnail_id = get_post_thumbnail_id( $postId );
	//アイキャッチ分
	resizeImg($postId,$post_thumbnail_id,'thumbnail');
	//カスタムフィールド分 ここ問題
}
add_action( 'post_updated', 'crateResizeThumbnail' );
//カスタムフィールド追加時リサイズ
function crateResizeFields($postId){
	$fields = get_fields($postId);
	foreach($fields as $key => $value){
		if(!$value) continue;
		$data = get_field_object($key,$postId);
		$type = $data['type'];
		if($type !== 'group') continue;
		foreach($value as $child_key => $child_value){
			if(!is_int($child_value)) continue;
			resizeImg($postId,$child_value,$child_key);
		}
	}
}
add_action( 'acf/save_post', 'crateResizeFields' );
//画像が削除されたらデータベースのデータも削除
function imgDelete($attachment_id ){
	$imgUrl = imgUrl($attachment_id);
	global $wpdb;
	$tablename =  $wpdb->prefix . "img_datas";
	$wpdb->query("DELETE FROM ".$tablename." WHERE img_data LIKE '%".$imgUrl."%'");
	return $attachment_id;
}
add_action( 'delete_attachment', 'imgDelete' );
//投稿が削除されたらデータベースのデータも削除
function deletePost($postId){
	global $wpdb;
	$tablename =  $wpdb->prefix . "img_datas";
	$wpdb->query("DELETE FROM ".$tablename." WHERE post_id = ".$postId);
}
add_action('before_delete_post', 'deletePost');
//pictureタグを作る
function picture($imgId,$sizeName){
	//alt
	$alt = get_post_meta( $imgId, '_wp_attachment_image_alt', true );
	if($alt) $alt = 'alt="'.$alt.'"';
	//データベースからデータ引っ張ってくる
	global $wpdb;
	//データベースに画像データがあるか確認
	$tablename =  $wpdb->prefix . "img_datas";
	$query = $wpdb->prepare( 
		"
		SELECT * 
		FROM $tablename
		WHERE 
		id = %d AND 
		img_name LIKE %s
		",
		$imgId,
		'%' . $sizeName . '%'
	);
	$results = $wpdb->get_results( $query );
	//データベースからの返り値データ
	$resultsDateFirest = $results[0];
	if($resultsDateFirest){//データがあったらpictureタグをつくる
		//連想オブジェクトに変換
		$resultsDateFirest->img_data = json_decode($resultsDateFirest->img_data, true);
		//配列に変換(foreach使うため)
		$resultsDateFirestArrays = (array) $resultsDateFirest;
		//Pictureタグで使う画像データを取得
		$imgUsePicturetags = $resultsDateFirestArrays['img_data'];
		$sourceMedia = '';//source mediaの部分
		global $imgDevicePixel;
		//3xからになっているので順番逆に
		$imgUsePicturetags = array_reverse($imgUsePicturetags, true);
		foreach($imgUsePicturetags as $windowSize => $imgUsePicturetag){
			$srcset = '';//source mediaの$srcset部分
			$src  = '';//src部分
			if($windowSize && $windowSize !== 'srcset'){
				$sourceMedia .= '<source media="(max-width:'.$windowSize.'px)" srcset="%s">';//source media部分
				//Pictureタグを使うかどうかをtrueに
				$usePicturetag = true;
			}elseif($windowSize === 'srcset') $sourceMedia .= ' <img src="%s" srcset="%s" class="responsive-img" '.$alt.'/>';//srcset部分responsive-img classつけている。
			//3xからになっているので順番逆に
			$imgUsePicturetag = array_reverse($imgUsePicturetag);
			$srcset .= $src;
			foreach($imgUsePicturetag as $pixcel => $imgRoot){
				//対応するデバイスピクセル分画像なければ1xを一番小さい画像で対応
				if(count($imgUsePicturetag) < $imgDevicePixel || $imgRoot === reset($imgUsePicturetag)) $src = urldecode(reset($imgUsePicturetag));
				$srcset .=	urldecode($imgRoot).' '.$pixcel;
				//,とスペーズつける
				if($imgRoot !== end($imgUsePicturetag)) $srcset .= ', ';
			}
			if($windowSize !== 'srcset') $sourceMedia = sprintf($sourceMedia, $srcset);
			elseif($windowSize === 'srcset') $sourceMedia = sprintf($sourceMedia, $src, $srcset); 
			
		}
		$Picturetag = '<picture>%s</picture>';
		//Pictureタグを使うかどうかがtrueならPictureタグで挟む
		if($usePicturetag)$sourceMedia = sprintf($Picturetag, $sourceMedia);
	}elseif(!$resultsDateFirest){
		$imgUrl = wp_get_attachment_url($imgId);
		$sourceMedia = '<img src="'.$imgUrl.'" class="responsive-img" '.$alt.'/>';
	}
	return $sourceMedia;
}
