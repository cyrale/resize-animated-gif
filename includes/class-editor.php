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
class RAGIF_Editor extends WP_Image_Editor {
	/**
	 * Animated GIF Resource.
	 *
	 * @var Rundiz\Image\Drivers\Imagick
	 */
	protected $image;

	protected $Imagick;

	public function __destruct() {
		if ( $this->image instanceof Rundiz\Image\Drivers\Imagick ) {
			$this->image->clear();
		}
	}

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
		if ( ! extension_loaded( 'imagick' )
		     || ! class_exists( 'Imagick', false )
		     || ! class_exists( 'ImagickPixel', false )
		     || ! class_exists( '\Rundiz\Image\Drivers\Imagick' )
		) {
			return false;
		}

		if ( version_compare( phpversion( 'imagick' ), '2.2.0', '<' ) ) {
			return false;
		}

		$required_methods = [
			'clear',
			'destroy',
			'valid',
			'getimage',
			'writeimage',
			'getimageblob',
			'getimagegeometry',
			'getimageformat',
			'setimageformat',
			'setimagecompression',
			'setimagecompressionquality',
			'setimagepage',
			'setoption',
			'scaleimage',
			'cropimage',
			'rotateimage',
			'flipimage',
			'flopimage',
			'readimage',
		];

		// Now, test for deep requirements within Imagick.
		$class_methods = array_map( 'strtolower', get_class_methods( 'Imagick' ) );
		if ( array_diff( $required_methods, $class_methods ) ) {
			return false;
		}

		return true;
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
		if ( $mime_type === 'image/gif' ) {
			$imagick_extension = strtoupper( self::get_extension( $mime_type ) );

			if ( ! $imagick_extension ) {
				return false;
			}

			try {
				return ( (bool) @Imagick::queryFormats( $imagick_extension ) );
			} catch ( Exception $e ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Loads image from $this->file into new GD Resource.
	 *
	 * @return true|WP_Error True if loaded; WP_Error on failure.
	 */
	public function load() {
		if ( $this->image instanceof Rundiz\Image\Drivers\Imagick ) {
			return true;
		}

		if ( ! is_file( $this->file ) && ! preg_match( '|^https?://|', $this->file ) ) {
			return new WP_Error( 'error_loading_image', __( 'File doesn&#8217;t exist?' ), $this->file );
		}

		/*
		 * Even though Imagick uses less PHP memory than GD, set higher limit
		 * for users that have low PHP.ini limits.
		 */
		wp_raise_memory_limit( 'image' );

		try {
			$this->image   = new \Rundiz\Image\Drivers\Imagick( $this->file );
			$this->Imagick = new Imagick();

			// Reading image after Imagick instantiation because `setResolution`
			// only applies correctly before the image is read.
			$this->Imagick->readImage( $this->file );

			if ( ! $this->Imagick->valid() ) {
				return new WP_Error( 'invalid_image', __( 'File is not an image.' ), $this->file );
			}

			$this->mime_type = $this->get_mime_type( $this->Imagick->getImageFormat() );
		} catch ( Exception $e ) {
			return new WP_Error( 'invalid_image', $e->getMessage(), $this->file );
		}

		$updated_size = $this->update_size();
		if ( is_wp_error( $updated_size ) ) {
			return $updated_size;
		}

		return true;
	}

	/**
	 * Sets or updates current image size.
	 *
	 * @param int $width
	 * @param int $height
	 *
	 * @return true|WP_Error
	 */
	protected function update_size( $width = null, $height = null ) {
		$size = null;
		if ( ! $width || ! $height ) {
			try {
				$size = $this->Imagick->getImageGeometry();
			} catch ( Exception $e ) {
				return new WP_Error( 'invalid_image', __( 'Could not read image size.' ), $this->file );
			}
		}

		if ( ! $width ) {
			$width = $size['width'];
		}

		if ( ! $height ) {
			$height = $size['height'];
		}

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
	 */
	public function resize( $max_w, $max_h, $crop = false ) {
		if ( ( $this->size['width'] == $max_w ) && ( $this->size['height'] == $max_h ) ) {
			return true;
		}

		$dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );
		if ( ! $dims ) {
			return new WP_Error( 'error_getting_dimensions', __( 'Could not calculate resized image dimensions' ) );
		}
		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

		if ( $crop ) {
			return $this->crop( $src_x, $src_y, $src_w, $src_h, $dst_w, $dst_h );
		}

		// Execute the resize.
		$resize_result = $this->image->resize( $dst_w, $dst_h );
		if ( ! $resize_result ) {
			return new WP_Error( 'image_resize_error', __('Image resize failed.'), $this->file );
		}

		return $this->update_size( $dst_w, $dst_h );
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
	 * @return array An array of resized images' metadata by size.
	 */
	public function multi_resize( $sizes ) {
		$metadata  = [];
		$orig_size = $this->size;

		foreach ( $sizes as $size => $size_data ) {
			if ( ! $this->image ) {
				$this->image = new \Rundiz\Image\Drivers\Imagick( $this->file );
			}

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

			$resize_result = $this->resize( $size_data['width'], $size_data['height'], $size_data['crop'] );
			$duplicate     = ( ( $orig_size['width'] == $size_data['width'] ) && ( $orig_size['height'] == $size_data['height'] ) );

			if ( ! is_wp_error( $resize_result ) && ! $duplicate ) {
				$resized = $this->_save( $this->image );

				$this->image->clear();
				$this->image = null;

				if ( ! is_wp_error( $resized ) && $resized ) {
					unset( $resized['path'] );
					$metadata[$size] = $resized;
				}
			}

			$this->size = $orig_size;
		}

		$this->image = new \Rundiz\Image\Drivers\Imagick( $this->file );

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
	 * @return bool|WP_Error
	 */
	public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false ) {
		if ( $src_abs ) {
			$src_w -= $src_x;
			$src_h -= $src_y;
		}

		$crop_result = $this->image->crop( $src_w, $src_h, $src_x, $src_y );
		if ( ! $crop_result ) {
			return new WP_Error( 'image_crop_error', __('Image crop failed.'), $this->file );
		}

		$resize_result = $this->image->resize( $dst_w, $dst_h );
		if ( ! $resize_result ) {
			return new WP_Error( 'image_crop_error', __('Image crop failed.'), $this->file );
		}

		return $this->update_size( $dst_w, $dst_h );
	}

	/**
	 * Rotates current image counter-clockwise by $angle.
	 * Ported from image-edit.php
	 *
	 * @param float $angle
	 * @return true|WP_Error
	 */
	public function rotate( $angle ) {
		$rotate_result = $this->image->rotate( $angle );
		if ( ! $rotate_result ) {
			return new WP_Error( 'image_rotate_error', __('Image rotate failed.'), $this->file );
		}

		return true;
	}

	/**
	 * Flips current image.
	 *
	 * @param bool $horz Flip along Horizontal Axis
	 * @param bool $vert Flip along Vertical Axis
	 *
	 * @return true|WP_Error
	 */
	public function flip( $horz, $vert ) {
		$error_msg = new WP_Error( 'image_flip_error', __('Image flip failed.'), $this->file );

		if ( $horz && ! $this->image->rotate( 'hor' ) ) {
			return $error_msg;
		}

		if ( $vert && ! $this->image->rotate( 'vrt' ) ) {
			return $error_msg;
		}

		return true;
	}

	/**
	 * Saves current in-memory image to file.
	 *
	 * @since 3.5.0
	 *
	 * @param string|null $filename
	 * @param string|null $mime_type
	 * @return array|WP_Error {'path'=>string, 'file'=>string, 'width'=>int, 'height'=>int, 'mime-type'=>string}
	 */
	public function save( $filename = null, $mime_type = null ) {
		$saved = $this->_save( $this->image, $filename, $mime_type );

		return $saved;
	}

	/**
	 *
	 * @param Rundiz\Image\Drivers\Imagick $image
	 * @param string $filename
	 * @param string $mime_type
	 * @return array|WP_Error
	 */
	protected function _save( $image, $filename = null, $mime_type = null ) {
		list( $filename, $extension, $mime_type ) = $this->get_output_format( $filename, $mime_type );

		if ( ! $filename ) {
			$filename = $this->generate_filename( null, null, $extension );
		}

		if ( ! $this->make_image( $filename, [ $image, 'save' ], [ $filename ] ) ) {
			return new WP_Error( 'image_save_error', __( 'Image save failed.' ), $filename );
		}

		// Set correct file permissions
		$stat = stat( dirname( $filename ) );
		$perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
		@ chmod( $filename, $perms );

		/** This filter is documented in wp-includes/class-wp-image-editor-gd.php */
		return array(
			'path'      => $filename,
			'file'      => wp_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
			'width'     => $this->size['width'],
			'height'    => $this->size['height'],
			'mime-type' => $mime_type,
		);
	}


	/**
	 * Returns stream of current image.
	 *
	 * @since 3.5.0
	 *
	 * @param string $mime_type The mime type of the image.
	 * @return bool|WP_Error True on success, WP_Error object on failure.
	 */
	public function stream( $mime_type = null ) {
		list( $filename, $extension, $mime_type ) = $this->get_output_format( null, $mime_type );

		try {
			// Temporarily change format for stream
			$this->image->Imagick->setImageFormat( strtoupper( $extension ) );

			// Output stream of image content
			header( "Content-Type: $mime_type" );
			print $this->image->Imagick->getImageBlob();

			// Reset Image to original Format
			$this->image->Imagick->setImageFormat( $this->get_extension( $this->mime_type ) );
		}
		catch ( Exception $e ) {
			return new WP_Error( 'image_stream_error', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Either calls editor's save function or handles file as a stream.
	 *
	 * @since 3.5.0
	 *
	 * @param string|stream $filename
	 * @param callable $function
	 * @param array $arguments
	 * @return bool
	 */
	protected function make_image( $filename, $function, $arguments ) {
		if ( wp_is_stream( $filename ) )
			$arguments[1] = null;

		return parent::make_image( $filename, $function, $arguments );
	}
}
