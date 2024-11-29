<?php
// Database connection
$host = 'localhost';
$port = '5432';
$dbname = 'SharingRide';
$user = 'postgres';
$password = '123';
$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

// Function: Request a Ride
function requestRide($riderId, $pickupLat, $pickupLong, $dropoffLat, $dropoffLong) {
    global $pdo;
    try {
        $pdo->beginTransaction();

        // Insert pickup and dropoff locations
        $stmt = $pdo->prepare("INSERT INTO Locations (latitude, longitude) VALUES (:lat, :long) RETURNING location_id");
        $stmt->execute([':lat' => $pickupLat, ':long' => $pickupLong]);
        $pickupLocationId = $stmt->fetchColumn();

        $stmt->execute([':lat' => $dropoffLat, ':long' => $dropoffLong]);
        $dropoffLocationId = $stmt->fetchColumn();

        // Create ride
        $stmt = $pdo->prepare("INSERT INTO Rides (pickup_location_id, dropoff_location_id, status, start_time) 
            VALUES (:pickup_id, :dropoff_id, 'requested', NOW()) RETURNING ride_id");
        $stmt->execute([':pickup_id' => $pickupLocationId, ':dropoff_id' => $dropoffLocationId]);
        $rideId = $stmt->fetchColumn();

        // Link rider to ride
        $stmt = $pdo->prepare("INSERT INTO RideParticipants (ride_id, rider_id) VALUES (:ride_id, :rider_id)");
        $stmt->execute([':ride_id' => $rideId, ':rider_id' => $riderId]);

        $pdo->commit();
        return ['status' => 'success', 'ride_id' => $rideId];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// Function: Accept a Ride
function acceptRide($rideId, $driverId) {
    global $pdo;
    try {
        $pdo->beginTransaction();

        // Lock ride to prevent other drivers from accepting
        $stmt = $pdo->prepare("SELECT * FROM Rides WHERE ride_id = :ride_id AND status = 'requested' FOR UPDATE");
		
        $stmt->execute([':ride_id' => $rideId]);
        $ride = $stmt->fetch(PDO::FETCH_ASSOC);
		
        if (!$ride) {
            throw new Exception("Ride not found or already accepted.");
        }

        // Update ride status to 'accepted'
        $stmt = $pdo->prepare("UPDATE Rides SET status = 'accepted' WHERE ride_id = :ride_id");
        $stmt->execute([':ride_id' => $rideId]);

        // Link driver to ride
        $stmt = $pdo->prepare("UPDATE RideParticipants SET driver_id = :driver_id WHERE ride_id = :ride_id");
        $stmt->execute([':driver_id' => $driverId, ':ride_id' => $rideId]);

        $pdo->commit();
        return ['status' => 'success', 'message' => 'Ride accepted'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// Function: Complete a Ride
function completeRide($rideId) {
    global $pdo;
    try {
        $pdo->beginTransaction();

        // Lock ride to prevent concurrent updates
        $stmt = $pdo->prepare("SELECT * FROM Rides WHERE ride_id = :ride_id AND status = 'accepted' FOR UPDATE");
        $stmt->execute([':ride_id' => $rideId]);
        $ride = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ride) {
            throw new Exception("Ride not found or cannot be completed.");
        }

        // Update ride status to 'completed' and set end time
        $stmt = $pdo->prepare("UPDATE Rides SET status = 'completed', end_time = NOW() WHERE ride_id = :ride_id");
        $stmt->execute([':ride_id' => $rideId]);

        $pdo->commit();
        return ['status' => 'success', 'message' => 'Ride completed'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// Function: Provide Feedback
function provideFeedback($rideId, $riderRating, $driverRating, $riderComments, $driverComments) {
    global $pdo;
    try {
        $pdo->beginTransaction();

        // Insert feedback
        $stmt = $pdo->prepare("INSERT INTO Feedback (rider_rating, driver_rating, rider_comments, driver_comments) 
            VALUES (:rider_rating, :driver_rating, :rider_comments, :driver_comments) RETURNING feedback_id");
        $stmt->execute([
            ':rider_rating' => $riderRating,
            ':driver_rating' => $driverRating,
            ':rider_comments' => $riderComments,
            ':driver_comments' => $driverComments
        ]);
        $feedbackId = $stmt->fetchColumn();

        // Link feedback to ride
        $stmt = $pdo->prepare("INSERT INTO RideFeedback (ride_id, feedback_id) VALUES (:ride_id, :feedback_id)");
        $stmt->execute([':ride_id' => $rideId, ':feedback_id' => $feedbackId]);

        $pdo->commit();
        return ['status' => 'success', 'message' => 'Feedback provided'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// API Endpoints
$requestMethod = $_SERVER['REQUEST_METHOD'];
$endpoint = $_GET['endpoint'] ?? '';
$data = json_decode(file_get_contents('php://input'), true);

if ($requestMethod === 'POST') {
    if ($endpoint === 'request_ride') {
        echo json_encode(requestRide($data['rider_id'], $data['pickup_lat'], $data['pickup_long'], $data['dropoff_lat'], $data['dropoff_long']));
    } elseif ($endpoint === 'accept_ride') {
        echo json_encode(acceptRide($data['ride_id'], $data['driver_id']));
    } elseif ($endpoint === 'complete_ride') {
        echo json_encode(completeRide($data['ride_id']));
    } elseif ($endpoint === 'provide_feedback') {
        echo json_encode(provideFeedback($data['ride_id'], $data['rider_rating'], $data['driver_rating'], $data['rider_comments'], $data['driver_comments']));
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
