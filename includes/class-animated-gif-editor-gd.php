<?php
/**
 * Resize animated GIF Editor.
 *
 * @since   1.0.0
 * @package Resize_Animated_GIF
 */

/**
 * Resize animated GIF Editor.
 *
 * @since 1.0.0
 */
class Animated_GIF_Editor_GD extends WP_Image_Editor_GD {

	protected $image_animated_gif;

	/**
	 * Checks to see if current environment supports resize of animated GIF.
	 *
	 * @static
	 *
	 * @param array $args
	 * @return bool
	 */
	public static function test( $args = array() ) {
		// First, test Imagick's extension and classes.
		if ( ! class_exists( '\GifFrameExtractor\GifFrameExtractor' )
		     || ! class_exists( '\GifCreator\GifCreator' )
		) {
			return false;
		}

		return parent::test( $args );
	}

	/**
	 * Checks to see if editor supports the mime-type specified.
	 *
	 * @static
	 *
	 * @param string $mime_type
	 * @return bool
	 */
	public static function supports_mime_type( $mime_type ) {
		$image_types = imagetypes();
		switch ( $mime_type ) {
			case 'image/gif':
				return ( $image_types & IMG_GIF ) != 0;
		}

		return false;
	}

	/**
	 * Sets or updates current image size.
	 *
	 * @param int $width
	 * @param int $height
	 * @return true
	 */
	protected function update_size( $width = null, $height = null ) {
		if ( ! \GifFrameExtractor\GifFrameExtractor::isAnimatedGif( $this->file ) ) {
			parent::update_size( $width, $height );
		}

		// TODO: get width and height from $image_animated_gif instead of $image
		if ( ! $width )
			$width = imagesx( $this->image );

		if ( ! $height )
			$height = imagesy( $this->image );

		return parent::update_size( $width, $height );
	}

	/**
	 * Resizes current image.
	 *
	 * At minimum, either a height or width must be provided.
	 * If one of the two is set to null, the resize will
	 * maintain aspect ratio according to the provided dimension.
	 *
	 * @param  int|null $max_w Image width.
	 * @param  int|null $max_h Image height.
	 * @param  bool     $crop
	 *
	 * @return bool|WP_Error
	 * @throws Exception
	 */
	public function resize( $max_w, $max_h, $crop = false ) {
		if ( ! \GifFrameExtractor\GifFrameExtractor::isAnimatedGif( $this->file ) ) {
			parent::resize( $max_w, $max_h, $crop );
		}

		if ( ( $this->size['width'] == $max_w ) && ( $this->size['height'] == $max_h ) )
			return true;

		$resized = $this->_resize_animated_gif( $max_w, $max_h, $crop );

		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$this->image_animated_gif = $resized;

		return true;
	}

	/**
	 * @param  int|null $max_w Image width.
	 * @param  int|null $max_h Image height.
	 * @param  bool     $crop
	 *
	 * @return \GifCreator\GifCreator|WP_Error
	 * @throws Exception
	 */
	protected function _resize_animated_gif( $max_w, $max_h, $crop = false ) {
		$frame_resources = [];
		$durations       = [];

		$gfe = new \GifFrameExtractor\GifFrameExtractor();

		$dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );
		if ( ! $dims ) {
			return new WP_Error( 'error_getting_dimensions', __( 'Could not calculate resized image dimensions' ), $this->file );
		}
		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

		foreach ( $gfe->extract( $this->file ) as $frame ) {
			$resized = wp_imagecreatetruecolor( $dst_w, $dst_h );

			if ( function_exists( 'imageantialias' ) ) {
				imageantialias( $resized, true );
			}

			imagecopyresampled( $resized, $frame['image'], $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h );

			if ( is_resource( $resized ) ) {
				$frame_resources[] = $resized;
				$durations[]       = $frame['duration'];
			}
		}

		if ( empty( $frame_resources ) ) {
			return new WP_Error( 'image_resize_error', __( 'Image resize failed.' ), $this->file );
		}

		$this->update_size( $dst_w, $dst_h );

		try {
			$gc = new \GifCreator\GifCreator();
			$gc->create( $frame_resources, $durations, 0 );

			return $gc;
		} catch ( Exception $e ) {
			return new WP_Error( 'error_gif_creation', $e->getMessage(), $this->file );
		}
	}

	/**
	 * Resize multiple images from a single source.
	 *
	 * @since 3.5.0
	 *
	 * @param array $sizes {
	 *     An array of image size arrays. Default sizes are 'small', 'medium', 'medium_large', 'large'.
	 *
	 *     Either a height or width must be provided.
	 *     If one of the two is set to null, the resize will
	 *     maintain aspect ratio according to the provided dimension.
	 *
	 *     @type array $size {
	 *         Array of height, width values, and whether to crop.
	 *
	 *         @type int  $width  Image width. Optional if `$height` is specified.
	 *         @type int  $height Image height. Optional if `$width` is specified.
	 *         @type bool $crop   Optional. Whether to crop the image. Default false.
	 *     }
	 * }
	 *
	 * @return array An array of resized images' metadata by size.
	 * @throws Exception
	 */
	public function multi_resize( $sizes ) {
		if ( ! \GifFrameExtractor\GifFrameExtractor::isAnimatedGif( $this->file ) ) {
			parent::multi_resize( $sizes );
		}

		$metadata  = [];
		$orig_size = $this->size;

		foreach ( $sizes as $size => $size_data ) {
			if ( ! isset( $size_data['width'] ) && ! isset( $size_data['height'] ) ) {
				continue;
			}

			if ( ! isset( $size_data['width'] ) ) {
				$size_data['width'] = null;
			}
			if ( ! isset( $size_data['height'] ) ) {
				$size_data['height'] = null;
			}

			if ( ! isset( $size_data['crop'] ) ) {
				$size_data['crop'] = false;
			}

			$image     = $this->_resize_animated_gif( $size_data['width'], $size_data['height'], $size_data['crop'] );
			$duplicate = ( ( $orig_size['width'] == $size_data['width'] ) && ( $orig_size['height'] == $size_data['height'] ) );

			if ( ! is_wp_error( $image ) && ! $duplicate ) {
				$resized = $this->_save_animated_gif( $image );

				unset( $this->image_animated_gif );
				$this->image_animated_gif = null;

				if ( ! is_wp_error( $resized ) && $resized ) {
					unset( $resized['path'] );
					$metadata[ $size ] = $resized;
				}
			}

			$this->size = $orig_size;
		}

		return $metadata;
	}

	/**
	 * Crops Image.
	 *
	 * @param int  $src_x   The start x position to crop from.
	 * @param int  $src_y   The start y position to crop from.
	 * @param int  $src_w   The width to crop.
	 * @param int  $src_h   The height to crop.
	 * @param int  $dst_w   Optional. The destination width.
	 * @param int  $dst_h   Optional. The destination height.
	 * @param bool $src_abs Optional. If the source crop points are absolute.
	 *
	 * @return bool|WP_Error
	 * @throws Exception
	 */
	public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false ) {
		if ( ! \GifFrameExtractor\GifFrameExtractor::isAnimatedGif( $this->file ) ) {
			parent::crop( $src_x, $src_y, $src_w, $src_h, $dst_w, $dst_h, $src_abs );
		}

		$cropped = $this->_crop_animated_gif( $src_x, $src_y, $src_w, $src_h, $dst_w, $dst_h, $src_abs );

		if ( is_wp_error( $cropped ) ) {
			return $cropped;
		}

		$this->image_animated_gif = $cropped;

		return true;
	}

	/**
	 * @param int  $src_x   The start x position to crop from.
	 * @param int  $src_y   The start y position to crop from.
	 * @param int  $src_w   The width to crop.
	 * @param int  $src_h   The height to crop.
	 * @param int  $dst_w   Optional. The destination width.
	 * @param int  $dst_h   Optional. The destination height.
	 * @param bool $src_abs Optional. If the source crop points are absolute.
	 *
	 * @return \GifCreator\GifCreator|WP_Error
	 * @throws Exception
	 */
	protected function _crop_animated_gif( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false ) {
		$frame_resources = [];
		$durations       = [];

		// If destination width/height isn't specified, use same as
		// width/height from source.
		if ( ! $dst_w )
			$dst_w = $src_w;
		if ( ! $dst_h )
			$dst_h = $src_h;

		if ( $src_abs ) {
			$src_w -= $src_x;
			$src_h -= $src_y;
		}

		$gfe = new \GifFrameExtractor\GifFrameExtractor();

		foreach ( $gfe->extract( $this->file ) as $frame ) {
			$cropped = wp_imagecreatetruecolor( $dst_w, $dst_h );

			if ( function_exists( 'imageantialias' ) ) {
				imageantialias( $cropped, true );
			}

			imagecopyresampled( $cropped, $frame['image'], 0, 0, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h );

			if ( is_resource( $cropped ) ) {
				$frame_resources[] = $cropped;
				$durations[]       = $frame['duration'];
			}
		}

		if ( empty( $frame_resources ) ) {
			return new WP_Error( 'image_crop_error', __( 'Image crop failed.' ), $this->file );
		}

		$this->update_size( $dst_w, $dst_h );

		try {
			$gc = new \GifCreator\GifCreator();
			$gc->create( $frame_resources, $durations, 0 );

			return $gc;
		} catch ( Exception $e ) {
			return new WP_Error( 'error_gif_creation', $e->getMessage(), $this->file );
		}
	}

	/**
	 * Rotates current image counter-clockwise by $angle.
	 * Ported from image-edit.php
	 *
	 * @param float $angle
	 * @return true|WP_Error
	 */
//	public function rotate( $angle ) {
//		if ( ! \GifFrameExtractor\GifFrameExtractor::isAnimatedGif( $this->file ) ) {
//			parent::rotate( $angle );
//		}
//
//		$rotated = $this->_rotate_animated_gif( $angle );
//
//		if ( is_wp_error( $rotated ) ) {
//			return $rotated;
//		}
//
//		$this->image_animated_gif = $rotated;
//
//		return true;
//	}
//
//	protected function _rotate_animated_gif( $angle ) {
//		if ( ! function_exists('imagerotate') ) {
//			return new WP_Error( 'image_rotate_error', __( 'Image rotate failed.' ), $this->file );
//		}
//
//		$frame_resources = [];
//		$durations       = [];
//
//		$gfe = new \GifFrameExtractor\GifFrameExtractor();
//
//		foreach ( $gfe->extract( $this->file ) as $frame ) {
//			$transparency = imagecolorallocatealpha( $frame['image'], 255, 255, 255, 127 );
//			$rotated      = imagerotate( $frame['image'], $angle, $transparency );
//
//			if ( is_resource( $rotated ) ) {
//				imagealphablending( $rotated, true );
//				imagesavealpha( $rotated, true );
//
//				$frame_resources[] = $rotated;
//				$durations[]       = $frame['duration'];
//			}
//		}
//
//		if ( empty( $frame_resources ) ) {
//			return new WP_Error( 'image_rotate_error', __( 'Image rotate failed.' ), $this->file );
//		}
//
//		$this->update_size();
//
//		try {
//			$gc = new \GifCreator\GifCreator();
//			$gc->create( $frame_resources, $durations, 0 );
//
//			return $gc;
//		} catch ( Exception $e ) {
//			return new WP_Error( 'error_gif_creation', $e->getMessage(), $this->file );
//		}
//	}

	/**
	 * Flips current image.
	 *
	 * @param bool $horz Flip along Horizontal Axis
	 * @param bool $vert Flip along Vertical Axis
	 *
	 * @return true|WP_Error
	 */
//	public function flip( $horz, $vert ) {
//		if ( ! \GifFrameExtractor\GifFrameExtractor::isAnimatedGif( $this->file ) ) {
//			parent::flip( $horz, $vert );
//		}
//
//		$flipped = $this->_flip_animated_gif( $horz, $vert );
//
//		if ( is_wp_error( $flipped ) ) {
//			return $flipped;
//		}
//
//		$this->image_animated_gif = $flipped;
//
//		return true;
//	}
//
//	protected function _flip_animated_gif( $horz, $vert ) {
//		$w = $this->size['width'];
//		$h = $this->size['height'];
//
//		$frame_resources = [];
//		$durations       = [];
//
//		$gfe = new \GifFrameExtractor\GifFrameExtractor();
//
//		foreach ( $gfe->extract( $this->file ) as $frame ) {
//			$flipped = wp_imagecreatetruecolor( $w, $h );
//
//			if ( is_resource( $flipped ) ) {
//				$sx = $vert ? ( $w - 1 ) : 0;
//				$sy = $horz ? ( $h - 1 ) : 0;
//				$sw = $vert ? - $w : $w;
//				$sh = $horz ? - $h : $h;
//
//				if ( imagecopyresampled( $flipped, $this->image, 0, 0, $sx, $sy, $w, $h, $sw, $sh ) ) {
//					$frame_resources[] = $flipped;
//					$durations[]       = $frame['duration'];
//				}
//			}
//		}
//
//		if ( empty( $frame_resources ) ) {
//			return new WP_Error( 'image_flip_error', __('Image flip failed.'), $this->file );
//		}
//
//		try {
//			$gc = new \GifCreator\GifCreator();
//			$gc->create( $frame_resources, $durations, 0 );
//
//			return $gc;
//		} catch ( Exception $e ) {
//			return new WP_Error( 'error_gif_creation', $e->getMessage(), $this->file );
//		}
//	}

	/**
	 * Saves current in-memory image to file.
	 *
	 * @param string|null $filename
	 * @param string|null $mime_type
	 *
	 * @return array|WP_Error {'path'=>string, 'file'=>string, 'width'=>int, 'height'=>int, 'mime-type'=>string}
	 */
	public function save( $filename = null, $mime_type = null ) {
		if ( ! \GifFrameExtractor\GifFrameExtractor::isAnimatedGif( $this->file ) ) {
			parent::save( $filename = null, $mime_type = null );
		}

		$saved = $this->_save_animated_gif( $this->image_animated_gif, $filename, $mime_type );

		if ( ! is_wp_error( $saved ) ) {
			$this->file      = $saved['path'];
			$this->mime_type = $saved['mime-type'];
		}

		return $saved;
	}

	/**
	 * @param \GifCreator\GifCreator $image
	 * @param string|null            $filename
	 * @param string|null            $mime_type
	 *
	 * @return array|WP_Error {'path'=>string, 'file'=>string, 'width'=>int, 'height'=>int, 'mime-type'=>string}
	 */
	protected function _save_animated_gif( $image, $filename = null, $mime_type = null ) {
		list( $filename, $extension, $mime_type ) = $this->get_output_format( $filename, $mime_type );

		if ( ! $filename ) {
			$filename = $this->generate_filename( null, null, $extension );
		}

		if ( ! $this->make_image( $filename, [ $this, "_save_animated_gif_file" ], [ $image, $filename ] ) ) {
			return new WP_Error( 'image_save_error', __( 'Image Editor Save Failed' ) );
		}

		// Set correct file permissions
		$stat  = stat( dirname( $filename ) );
		$perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
		@ chmod( $filename, $perms );

		return [
			'path'      => $filename,
			'file'      => wp_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
			'width'     => $this->size['width'],
			'height'    => $this->size['height'],
			'mime-type' => $mime_type,
		];
	}

	/**
	 * @param \GifCreator\GifCreator $image
	 * @param string                 $filename
	 *
	 * @return bool
	 */
	public function _save_animated_gif_file( $image, $filename ) {
		file_put_contents( $filename, $image->getGif() );

		return true;
	}

	/**
	 * Returns stream of current image.
	 *
	 * @param string $mime_type The mime type of the image.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function stream( $mime_type = null ) {
		if ( ! \GifFrameExtractor\GifFrameExtractor::isAnimatedGif( $this->file ) ) {
			parent::save( $filename = null, $mime_type = null );
		}

		// Output stream of image content
		header( "Content-Type: $mime_type" );

		if ( ! empty( $this->image_animated_gif ) ) {
			echo $this->image_animated_gif->getGif();
		} else {
			echo file_get_contents( $this->file );
		}

		return true;
	}
}
