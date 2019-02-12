<?php
/**
 * The imagecreatefrombmp function converts a bmp to an image resource
 *
 * @author http://www.programmierer-forum.de/function-imagecreatefrombmp-laeuft-mit-allen-bitraten-t143137.htm
 * @package Imsanity
 */

if ( ! function_exists( 'imagecreatefrombmp' ) ) {

	/**
	 * Converts a bitmap (BMP) image into an image resource.
	 *
	 * @param string $filename The name of the image file.
	 * @return bool|object False, or a GD image resource.
	 */
	function imagecreatefrombmp( $filename ) {
		// version 1.1.
		if ( ! is_readable( $filename ) ) {
			/* translators: %s: the image filename */
			trigger_error( sprintf( __( 'imagecreatefrombmp: Can not open %s!', 'imsanity' ), $filename ), E_USER_WARNING );
			return false;
		}
		$fh = fopen( $filename, 'rb' );
		if ( ! $fh ) {
			/* translators: %s: the image filename */
			trigger_error( sprintf( __( 'imagecreatefrombmp: Can not open %s!', 'imsanity' ), $filename ), E_USER_WARNING );
			return false;
		}
		// read file header.
		$meta = unpack( 'vtype/Vfilesize/Vreserved/Voffset', fread( $fh, 14 ) );
		// check for bitmap.
		if ( 19778 != $meta['type'] ) {
			/* translators: %s: the image filename */
			trigger_error( sprintf( __( 'imagecreatefrombmp: %s is not a bitmap!', 'imsanity' ), $filename ), E_USER_WARNING );
			return false;
		}
		// read image header.
		$meta      += unpack( 'Vheadersize/Vwidth/Vheight/vplanes/vbits/Vcompression/Vimagesize/Vxres/Vyres/Vcolors/Vimportant', fread( $fh, 40 ) );
		$bytes_read = 40;

		// read additional bitfield header.
		if ( 3 == $meta['compression'] ) {
			$meta       += unpack( 'VrMask/VgMask/VbMask', fread( $fh, 12 ) );
			$bytes_read += 12;
		}

		// set bytes and padding.
		$meta['bytes'] = $meta['bits'] / 8;
		$meta['decal'] = 4 - ( 4 * ( ( $meta['width'] * $meta['bytes'] / 4 ) - floor( $meta['width'] * $meta['bytes'] / 4 ) ) );
		if ( 4 == $meta['decal'] ) {
			$meta['decal'] = 0;
		}

		// obtain imagesize.
		if ( $meta['imagesize'] < 1 ) {
			$meta['imagesize'] = $meta['filesize'] - $meta['offset'];
			// in rare cases filesize is equal to offset so we need to read physical size.
			if ( $meta['imagesize'] < 1 ) {
				$meta['imagesize'] = filesize( $filename ) - $meta['offset'];
				if ( $meta['imagesize'] < 1 ) {
					/* translators: %s: the image filename */
					trigger_error( sprintf( __( 'imagecreatefrombmp: Cannot obtain filesize of %s !', 'imsanity' ), $filename ), E_USER_WARNING );
					return false;
				}
			}
		}

		// calculate colors.
		$meta['colors'] = ! $meta['colors'] ? pow( 2, $meta['bits'] ) : $meta['colors'];

		// read color palette.
		$palette = array();
		if ( $meta['bits'] < 16 ) {
			$palette = unpack( 'l' . $meta['colors'], fread( $fh, $meta['colors'] * 4 ) );
			// in rare cases the color value is signed.
			if ( $palette[1] < 0 ) {
				foreach ( $palette as $i => $color ) {
					$palette[ $i ] = $color + 16777216;
				}
			}
		}

		// ignore extra bitmap headers.
		if ( $meta['headersize'] > $bytes_read ) {
			fread( $fh, $meta['headersize'] - $bytes_read );
		}

		// create gd image.
		$im   = imagecreatetruecolor( $meta['width'], $meta['height'] );
		$data = fread( $fh, $meta['imagesize'] );

		// uncompress data.
		switch ( $meta['compression'] ) {
			case 1:
				$data = rle8_decode( $data, $meta['width'] );
				break;
			case 2:
				$data = rle4_decode( $data, $meta['width'] );
				break;
		}

		$p    = 0;
		$vide = chr( 0 );
		$y    = $meta['height'] - 1;
		/* translators: %s: the image filename */
		$error = sprintf( __( 'imagecreatefrombmp: %s has not enough data!', 'imsanity' ), $filename );
		// loop through the image data beginning with the lower left corner.
		while ( $y >= 0 ) {
			$x = 0;
			while ( $x < $meta['width'] ) {
				switch ( $meta['bits'] ) {
					case 32:
					case 24:
						$part = substr( $data, $p, 3 );
						if ( ! $part ) {
							trigger_error( $error, E_USER_WARNING );
							return $im;
						}
						$color = unpack( 'V', $part . $vide );
						break;
					case 16:
						$part = substr( $data, $p, 2 );
						if ( ! $part ) {
							trigger_error( $error, E_USER_WARNING );
							return $im;
						}
						$color = unpack( 'v', $part );
						if ( empty( $meta['rMask'] ) || 0xf800 != $meta['rMask'] ) {
							$color[1] = ( ( $color[1] & 0x7c00 ) >> 7 ) * 65536 + ( ( $color[1] & 0x03e0 ) >> 2 ) * 256 + ( ( $color[1] & 0x001f ) << 3 ); // 555.
						} else {
							$color[1] = ( ( $color[1] & 0xf800 ) >> 8 ) * 65536 + ( ( $color[1] & 0x07e0 ) >> 3 ) * 256 + ( ( $color[1] & 0x001f ) << 3 ); // 565.
						}
						break;
					case 8:
						$color    = unpack( 'n', $vide . substr( $data, $p, 1 ) );
						$color[1] = $palette[ $color[1] + 1 ];
						break;
					case 4:
						$color    = unpack( 'n', $vide . substr( $data, floor( $p ), 1 ) );
						$color[1] = 0 == ( $p * 2 ) % 2 ? $color[1] >> 4 : $color[1] & 0x0F;
						$color[1] = $palette[ $color[1] + 1 ];
						break;
					case 1:
						$color = unpack( 'n', $vide . substr( $data, floor( $p ), 1 ) );
						switch ( ( $p * 8 ) % 8 ) {
							case 0:
								$color[1] = $color[1] >> 7;
								break;
							case 1:
								$color[1] = ( $color[1] & 0x40 ) >> 6;
								break;
							case 2:
								$color[1] = ( $color[1] & 0x20 ) >> 5;
								break;
							case 3:
								$color[1] = ( $color[1] & 0x10 ) >> 4;
								break;
							case 4:
								$color[1] = ( $color[1] & 0x8 ) >> 3;
								break;
							case 5:
								$color[1] = ( $color[1] & 0x4 ) >> 2;
								break;
							case 6:
								$color[1] = ( $color[1] & 0x2 ) >> 1;
								break;
							case 7:
								$color[1] = ( $color[1] & 0x1 );
								break;
						}
						$color[1] = $palette[ $color[1] + 1 ];
						break;
					default:
						/* translators: 1: the image filename 2: bitrate of image */
						trigger_error( sprintf( __( 'imagecreatefrombmp: %1$s has %2$d bits and this is not supported!', 'imsanity' ), $filename, $meta['bits'] ), E_USER_WARNING );
						return false;
				}
				imagesetpixel( $im, $x, $y, $color[1] );
				$x++;
				$p += $meta['bytes'];
			}
			$y--;
			$p += $meta['decal'];
		}
		fclose( $fh );
		return $im;
	}
	/**
	 * The original source for these functions no longer exists, but it appears to come from
	 * MSDN and has proliferated across many projects with only the stale link which now
	 * points to https://docs.microsoft.com/en-us/windows/desktop/gdi/bitmap-compression.
	 */
	/**
	 * Decoder for RLE8 compression in windows bitmaps.
	 *
	 * @param string  $str Data to decode.
	 * @param integer $width Image width.
	 *
	 * @return string
	 */
	function rle8_decode( $str, $width ) {
		$linewidth = $width + ( 3 - ( $width - 1 ) % 4 );
		$out       = '';
		$cnt       = strlen( $str );

		for ( $i = 0; $i < $cnt; $i++ ) {
			$o = ord( $str[ $i ] );
			switch ( $o ) {
				case 0: // ESCAPE.
					$i++;
					switch ( ord( $str[ $i ] ) ) {
						case 0: // NEW LINE.
							$padcnt = $linewidth - strlen( $out ) % $linewidth;
							if ( $padcnt < $linewidth ) {
								$out .= str_repeat( chr( 0 ), $padcnt ); // pad line.
							}
							break;
						case 1: // END OF FILE.
							$padcnt = $linewidth - strlen( $out ) % $linewidth;
							if ( $padcnt < $linewidth ) {
								$out .= str_repeat( chr( 0 ), $padcnt ); // pad line.
							}
							break 3;
						case 2: // DELTA.
							$i += 2;
							break;
						default: // ABSOLUTE MODE.
							$num = ord( $str[ $i ] );
							for ( $j = 0; $j < $num; $j++ ) {
								$out .= $str[ ++$i ];
							}
							if ( $num % 2 ) {
								$i++;
							}
					}
					break;
				default:
					$out .= str_repeat( $str[ ++$i ], $o );
			}
		}
		return $out;
	}

	/**
	 * Decoder for RLE4 compression in windows bitmaps.
	 *
	 * @param string  $str Data to decode.
	 * @param integer $width Image width.
	 * @return string
	 */
	function rle4_decode( $str, $width ) {
		$w         = floor( $width / 2 ) + ( $width % 2 );
		$linewidth = $w + ( 3 - ( ( $width - 1 ) / 2 ) % 4 );
		$pixels    = array();
		$cnt       = strlen( $str );
		$c         = 0;

		for ( $i = 0; $i < $cnt; $i++ ) {
			$o = ord( $str[ $i ] );
			switch ( $o ) {
				case 0: // ESCAPE.
					$i++;
					switch ( ord( $str[ $i ] ) ) {
						case 0: // NEW LINE.
							while ( 0 != count( $pixels ) % $linewidth ) {
								$pixels[] = 0;
							}
							break;
						case 1: // END OF FILE.
							while ( 0 != count( $pixels ) % $linewidth ) {
								$pixels[] = 0;
							}
							break 3;
						case 2: // DELTA.
							$i += 2;
							break;
						default: // ABSOLUTE MODE.
							$num = ord( $str[ $i ] );
							for ( $j = 0; $j < $num; $j++ ) {
								if ( 0 == $j % 2 ) {
									$c        = ord( $str[ ++$i ] );
									$pixels[] = ( $c & 240 ) >> 4;
								} else {
									$pixels[] = $c & 15;
								}
							}

							if ( 0 == $num % 2 ) {
								$i++;
							}
					}
					break;
				default:
					$c = ord( $str[ ++$i ] );
					for ( $j = 0; $j < $o; $j++ ) {
						$pixels[] = ( 0 == $j % 2 ? ( $c & 240 ) >> 4 : $c & 15 );
					}
			}
		}

		$out = '';
		if ( count( $pixels ) % 2 ) {
			$pixels[] = 0;
		}

		$cnt = count( $pixels ) / 2;

		for ( $i = 0; $i < $cnt; $i++ ) {
			$out .= chr( 16 * $pixels[ 2 * $i ] + $pixels[ 2 * $i + 1 ] );
		}

		return $out;
	}
}
