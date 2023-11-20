<?php

namespace KaufmannDigital\ThumbnailGenerators\ThumbnailGenerator;

use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Model\Thumbnail;
use Neos\Media\Domain\Model\ThumbnailGenerator\AbstractThumbnailGenerator;
use Neos\Media\Exception;

/**
 * A system-generated preview version of a Document (PDF, AI and EPS)
 */
class GraphicsMagicPdfGenerator extends AbstractThumbnailGenerator
{

    public function canRefresh(Thumbnail $thumbnail)
    {
        return (
            $thumbnail->getOriginalAsset() instanceof Document &&
            $this->isExtensionSupported($thumbnail) &&
            $this->imagineService instanceof \Imagine\Gmagick\Imagine &&
            extension_loaded('gmagick')
        );
    }

    public function refresh(Thumbnail $thumbnail)
    {
        try {
            $filenameWithoutExtension = pathinfo($thumbnail->getOriginalAsset()->getResource()->getFilename(), PATHINFO_FILENAME);
            $temporaryLocalCopyFilename = $thumbnail->getOriginalAsset()->getResource()->createTemporaryLocalCopy();
            $documentFile = sprintf(in_array($thumbnail->getOriginalAsset()->getResource()->getFileExtension(), $this->getOption('paginableDocuments')) ? '%s[0]' : '%s', $temporaryLocalCopyFilename);

            $width = $thumbnail->getConfigurationValue('width') ?: $thumbnail->getConfigurationValue('maximumWidth');
            $height = $thumbnail->getConfigurationValue('height') ?: $thumbnail->getConfigurationValue('maximumHeight');

            $im = new \Gmagick();
            $im->setResolution($this->getOption('resolution'), $this->getOption('resolution'));
            try {
                $readResult = $im->readImage($documentFile);
            } catch (\GmagickException $e) {
                $readResult = $e;
            }

            if ($readResult instanceof \Exception) {
                $filename = $thumbnail->getOriginalAsset()->getResource()->getFilename();
                $sha1 = $thumbnail->getOriginalAsset()->getResource()->getSha1();
                $message = $readResult instanceof \GmagickException ? $readResult->getMessage() : 'unknown';
                throw new \RuntimeException(
                    sprintf(
                        'Could not read image (filename: %s, SHA1: %s) for thumbnail generation. Maybe the ImageMagick security policy denies reading the format? Error: %s',
                        $filename,
                        $sha1,
                        $message
                    ),
                    1656518085
                );
            }
            $im->setImageFormat('png');
            $im->setImageBackgroundColor(new \GmagickPixel('white'));
            $im->setImageCompose(\Gmagick::COMPOSITE_OVER);

            /**
             * @see http://pecl.php.net/bugs/bug.php?id=22435
             */
            if (method_exists($im, 'flattenImages')) {
                try {
                    $im = $im->flattenImages();
                } catch (\GmagickException $e) {
                    throw new \RuntimeException('Flatten operation failed', $e->getCode(), $e);
                }
            }

            $im->thumbnailImage($width, $height, true);
            $resource = $this->resourceManager->importResourceFromContent($im->getimagesblob(), $filenameWithoutExtension . '.png');
            $im->destroy();

            $thumbnail->setResource($resource);
            $thumbnail->setWidth($width);
            $thumbnail->setHeight($height);
        } catch (\Exception $exception) {
            $filename = $thumbnail->getOriginalAsset()->getResource()->getFilename();
            $sha1 = $thumbnail->getOriginalAsset()->getResource()->getSha1();
            $message = sprintf('Unable to generate thumbnail for the given document (filename: %s, SHA1: %s)', $filename, $sha1);
            throw new Exception\NoThumbnailAvailableException($message, 1433109652, $exception);
        }

        unset($temporaryLocalCopyFilename);
    }

}
