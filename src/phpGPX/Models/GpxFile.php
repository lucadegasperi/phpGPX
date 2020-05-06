<?php
/**
 * Created            17/02/2017 17:46
 * @author            Jakub Dubec <jakub.dubec@gmail.com>
 */

namespace phpGPX\Models;

use phpGPX\Helpers\DateTimeHelper;
use phpGPX\Helpers\SerializationHelper;
use phpGPX\Parsers\ExtensionParser;
use phpGPX\Parsers\MetadataParser;
use phpGPX\Parsers\PointParser;
use phpGPX\Parsers\RouteParser;
use phpGPX\Parsers\TrackParser;
use phpGPX\phpGPX;

/**
 * Class GpxFile
 * Representation of GPX file.
 * @package phpGPX\Models
 */
class GpxFile implements Summarizable
{
	/**
	 * A list of waypoints.
	 * @var Point[]
	 */
	public $waypoints;

	/**
	 * A list of routes.
	 * @var Route[]
	 */
	public $routes;

	/**
	 * A list of tracks.
	 * @var Track[]
	 */
	public $tracks;

	/**
	 * Metadata about the file.
	 * The original GPX 1.1 attribute.
	 * @var Metadata|null
	 */
	public $metadata;

	/**
	 * @var Extensions|null
	 */
	public $extensions;

	/**
	 * Creator of GPX file.
	 * @var string|null
	 */
		public $creator;

	/**
	 * GpxFile constructor.
	 */
	public function __construct()
	{
		$this->waypoints = [];
		$this->routes = [];
		$this->tracks = [];
		$this->metadata = null;
		$this->extensions = null;
		$this->creator = null;
	}


	/**
	 * Serialize object to array
	 * @return array
	 */
	public function toArray()
	{
		return [
			'creator' => SerializationHelper::stringOrNull($this->creator),
			'metadata' => SerializationHelper::serialize($this->metadata),
			'waypoints' => SerializationHelper::serialize($this->waypoints),
			'routes' => SerializationHelper::serialize($this->routes),
			'tracks' => SerializationHelper::serialize($this->tracks),
			'extensions' => SerializationHelper::serialize($this->extensions)
		];
	}

	/**
	 * Return JSON representation of GPX file with statistics.
	 * @return string
	 */
	public function toJSON()
	{
		return json_encode($this->toArray(), phpGPX::$PRETTY_PRINT ? JSON_PRETTY_PRINT : null);
	}

	/**
	 * Create XML representation of GPX file.
	 * @return \DOMDocument
	 */
	public function toXML()
	{
		$document = new \DOMDocument("1.0", 'UTF-8');

		$gpx = $document->createElementNS("http://www.topografix.com/GPX/1/1", "gpx");
		$gpx->setAttribute("version", "1.1");
		$gpx->setAttribute("creator", $this->creator ? $this->creator : phpGPX::getSignature());

		ExtensionParser::$usedNamespaces = [];

		if (!empty($this->metadata)) {
			$gpx->appendChild(MetadataParser::toXML($this->metadata, $document));
		}

		foreach ($this->waypoints as $waypoint) {
			$gpx->appendChild(PointParser::toXML($waypoint, $document));
		}

		foreach ($this->routes as $route) {
			$gpx->appendChild(RouteParser::toXML($route, $document));
		}

		foreach ($this->tracks as $track) {
			$gpx->appendChild(TrackParser::toXML($track, $document));
		}

		if (!empty($this->extensions)) {
			$gpx->appendChild(ExtensionParser::toXML($this->extensions, $document));
		}

		// Namespaces
		$schemaLocationArray = [
			'http://www.topografix.com/GPX/1/1',
			'http://www.topografix.com/GPX/1/1/gpx.xsd'
		];

		foreach (ExtensionParser::$usedNamespaces as $usedNamespace) {
			$gpx->setAttributeNS(
				"http://www.w3.org/2000/xmlns/",
				sprintf("xmlns:%s", $usedNamespace['prefix']),
				$usedNamespace['namespace']
			);

			$schemaLocationArray[] = $usedNamespace['namespace'];
			$schemaLocationArray[] = $usedNamespace['xsd'];
		}

		$gpx->setAttributeNS(
			'http://www.w3.org/2001/XMLSchema-instance',
			'xsi:schemaLocation',
			implode(" ", $schemaLocationArray)
		);

		$document->appendChild($gpx);

		if (phpGPX::$PRETTY_PRINT) {
			$document->formatOutput = true;
			$document->preserveWhiteSpace = true;
		}
		return $document;
	}

	public function toGeoJSON($format = null)
    {
        $document = [
            "type" => "FeatureCollection",
            "name" => "track_points",
            "crs" => ["type" => "name", "properties" => ["name" => "urn:ogc:def:crs:OGC:1.3:CRS84"]],
            "features" => []
        ];

        if (is_null($format) || $format === phpGPX::GEOJSON_POINTS_FORMAT) {
            foreach ($this->tracks as $trackIndex => $track) {
                foreach ($track->segments as $segmentIndex => $segment) {
                    foreach ($segment->getPoints() as $index => $point) {
                        $coordinates = [
                            (float)$point->longitude,
                            (float)$point->latitude
                        ];
                        if (!is_null($point->elevation) || $point->elevation > 0) {
                            $coordinates[] = (float)$point->elevation;
                        }
                        $document['features'][] = [
                            "type" => "Feature",
                            "properties" => [
                                "ele" => $point->elevation,
                                "track_fid" => $trackIndex,
                                "track_seg_id" => $segmentIndex,
                                "track_seg_point_id" => $index,
                                "time" => DateTimeHelper::formatDateTime($point->time, phpGPX::$DATETIME_FORMAT, phpGPX::$DATETIME_TIMEZONE_OUTPUT),
                            ],
                            "geometry" => [
                                "type" => "Point",
                                "coordinates" => $coordinates
                            ]
                        ];
                    }
                }
            }
        } else if ($format === phpGPX::GEOJSON_LINES_FORMAT) {
            foreach($this->tracks as $track) {
                $lineCoordinates = [];
                $times = [];
                foreach($track->getPoints() as $point) {
                    $coordinates = [(float)$point->longitude, (float)$point->latitude];
                    if(!is_null($point->elevation) || $point->elevation > 0) {
                        $coordinates[] = (float)$point->elevation;
                    }
                    $lineCoordinates[] = $coordinates;
                    $times[] = DateTimeHelper::formatDateTime($point->time, phpGPX::$DATETIME_FORMAT, phpGPX::$DATETIME_TIMEZONE_OUTPUT);
                }

                $document['features'][] = [
                    "type" => "Feature",
                    "properties" => [
                        "name" => $track->name,
                        "time" => $times[0],
                        "coordTimes" => $times
                    ],
                    "geometry" => [
                        "type" => "LineString",
                        "coordinates" => $lineCoordinates
                    ]
                ];
            }
        }

        foreach ($this->waypoints as $waypoint) {
            $coordinates = [
                (float)$waypoint->longitude,
                (float)$waypoint->latitude
            ];
            if (!is_null($waypoint->elevation) || $waypoint->elevation > 0) {
                $coordinates[] = (float)$waypoint->elevation;
            }
            $document['features'][] = [
                "type" => "Feature",
                "properties" => [
                    "type" => $waypoint->type,
                    "name" => $waypoint->name,
                    "comment" => $waypoint->comment,
                    "description" => $waypoint->description,
                    "ele" => $waypoint->elevation,
                    "time" => DateTimeHelper::formatDateTime($waypoint->time, phpGPX::$DATETIME_FORMAT,
                        phpGPX::$DATETIME_TIMEZONE_OUTPUT),
                ],
                "geometry" => [
                    "type" => "Point",
                    "coordinates" => $coordinates
                ]
            ];
        }

        return json_encode($document);
    }

	/**
	 * Save data to file according to selected format.
	 * @param string $path
	 * @param string $format
	 */
	public function save($path, $format)
	{
		switch ($format) {
			case phpGPX::XML_FORMAT:
				$document = $this->toXML();
				$document->save($path);
				break;
			case phpGPX::JSON_FORMAT:
				file_put_contents($path, $this->toJSON());
				break;
            case phpGPX::GEOJSON_POINTS_FORMAT:
                file_put_contents($path, $this->toGeoJSON(phpGPX::GEOJSON_POINTS_FORMAT));
                break;
            case phpGPX::GEOJSON_LINES_FORMAT:
                file_put_contents($path, $this->toGeoJSON(phpGPX::GEOJSON_LINES_FORMAT));
                break;
			default:
				throw new \RuntimeException("Unsupported file format!");
		};
	}
}
