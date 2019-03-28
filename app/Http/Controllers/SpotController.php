<?php

namespace App\Http\Controllers;

use App\Category;
use App\Classification;
use App\Descriptors;
use App\Spot;
use App\Type;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Validator;

class SpotController extends Controller
{
    private $validator;

    /**
     * Constructor to prevent unauthenticated access to sensitive routes.
     */
    public function __construct()
    {
        $this->middleware('auth:api')->except(['get']);
    }

    private function filterSpotsVisible(Collection $spots, User $user = null)
    {
        return $spots->filter(function (Spot $spot) use ($user) {
            $requiredViewPermission = $spot->classification->view_permission;
            if ($user) {
                if (!$spot->approved && $user->can('view unapproved spots')) {
                    return true;
                }

                return ($spot->approved && $user->can($requiredViewPermission)) || ($spot->author->id == $user->id);
            }

            return $spot->approved && !$requiredViewPermission;
        })->values()->all();
    }

    private function filterClassifications(Collection $classifications, User $user = null)
    {
        return $classifications->filter(function (Classification $classification) use ($user) {
            $requiredCreatePermission = $classification->create_permission;
            if ($user == null) {
                // The user is not logged in, filter out all classifications. (should never happen)
                return false;
            } else {
                return $user->can($requiredCreatePermission);
            }
        })->values()->all();
    }

    /**
     * Function to get the nap spots the current user may see.
     *
     * @param Request $request The Http request.
     *
     * @return Collection The collection of spots a user is supposed to see.
     */
    public function get(Request $request)
    {
        return $this->filterSpotsVisible(Spot::all(), auth('api')->user());
    }

    private function validateSentDescriptors(Request $request, Type $type)
    {
        $validatedDescriptors = [];
        $sentDescriptors = collect();
        $requestDescriptors = $request->input('descriptors');
        $categoryRequiredDescriptors = $type->category->descriptors;
        // Loop through all of the sent descriptors to verify they are required by the spots category
        foreach ($requestDescriptors as $descriptorId => $value) {
            if ($descriptor = Descriptors::find($descriptorId)) {
                // Make sure the descriptor exists
                if ($categoryRequiredDescriptors->pluck('id')->contains($descriptorId)) {
                    // Verify the value is one of the allowed values
                    $allowedValues = collect(explode('|', $descriptor->allowed_values));
                    if ($allowedValues->contains($value)) {
                        $sentDescriptors->push($descriptor);
                        $validatedDescriptors[$descriptorId] = ['value' => $value];
                    } else {
                        $this->validator->errors()->add('Descriptors', "Invalid value, $value, supplied for descriptor $descriptorId");
                    }
                }
            } else {
                $this->validator->errors()->add('Descriptors', "Descriptor $descriptorId does not exist");
            }
        }
        // Compare the descriptors that the category requires with the sent descriptors to make sure no missing required descriptors
        $missingDescriptors = $categoryRequiredDescriptors->diff($sentDescriptors);
        if ($missingDescriptors->count()) {
            $this->validator->errors()->add('Missing Descriptors', $missingDescriptors->pluck('name')->toJson());
        }

        return $validatedDescriptors;
    }

    private function checkUserCanMakeRequestedSpot(User $user, Category $category)
    {
        $crowdsource = $category->crowdsource;
        $canMakeDesignatedSpots = $user->can('make designated spots');

        if (!($crowdsource || $canMakeDesignatedSpots)) {
            $this->validator->errors()->add('Permission Error', 'The category you specified does not allow crowdsourced spots.');
        }

        return $crowdsource ? true : $canMakeDesignatedSpots;
    }

    private function verifyRequestHasRequiredData(Request $request)
    {
        $rules = [
            'notes'             => 'sometimes|string|nullable',
            'descriptors'       => 'required',
            'type_name'         => 'required',
            'lat'               => 'required|numeric',
            'lng'               => 'required|numeric',
            'classification_id' => 'required|numeric',
        ];
        $this->validator = \Illuminate\Support\Facades\Validator::make(Input::all(), $rules);
    }

    private function getProperClassificationForUser(User $user, Classification $classification)
    {
        if (!$user->can('approve spots')) {
            $newClassification = Classification::where('category_id', $classification->category_id)->where('name', 'Under Review')->first();
            if ($newClassification) {
                $classification = $newClassification;
            } else {
                $this->validator->errors()->add('Internal Error', 'Under review classification does not exist for the given category.');
            }
        }

        return $classification;
    }

    private function validateSentData(Request $request, MessageBag $response)
    {
        $this->verifyRequestHasRequiredData($request);

        if (!($this->validator instanceof Validator)) {
            $response->add('Internal Error', 'Invalid Validator');

            return response($response, 500);
        }

        // Initial validation, just that required fields are sent
        if ($this->validator->fails()) {
            return false;
        }

        $user = $request->user();
        $type = Type::where('name', $request->input('type_name'))->first();
        $classification = Classification::find($request->input('classification_id'));
        $classification = $this->getProperClassificationForUser($user, $classification);

        if (!$type || !$classification) {
            $this->validator->errors()->add('Invalid Error', 'You\'ve provided an invalid type or classification.');
        }

        $this->checkUserCanMakeRequestedSpot($user, $type->category);
        $descriptors = $this->validateSentDescriptors($request, $type);

        // Final validation check before spot creation.
        if ($this->validator->fails()) {
            return false;
        }

        return [
            'user'           => $user,
            'type'           => $type,
            'descriptors'    => $descriptors,
            'classification' => $classification,
        ];
    }

    /**
     * Endpoint hit for creating nap spots.
     *
     * @param Request $request The HTTP request.
     *
     * @return Spot|MessageBag The new spot or the errors that occurred while parsing the request.
     */
    public function store(Request $request)
    {
        $response = new MessageBag();
        $validatedData = $this->validateSentData($request, $response);
        if (!$validatedData) {
            return response($this->validator->errors(), 400);
        }

        $user = $validatedData['user'];
        $type = $validatedData['type'];
        $descriptors = $validatedData['descriptors'];
        $classification = $validatedData['classification'];
        $canApproveSpots = $user->can('approve spots');

        $spot = Spot::create([
            'notes'             => $request->input('notes'),
            'type_id'           => $type->id,
            'lat'               => $request->input('lat'),
            'lng'               => $request->input('lng'),
            'approved'          => $canApproveSpots ? 1 : 0,
            'user_id'           => $user->id,
            'classification_id' => $classification->id,
        ]);

        if ($spot instanceof Spot) {
            $spot->descriptors()->attach($descriptors);
        }

        $response->add('spot', $spot);
        if ($canApproveSpots) {
            $response->add('message', 'Your spot has been created successfully!');
        } else {
            $response->add('message', "The spot you created will be reviewed and published once approved! Until then hang tight, you'll get an email when your spot has been reviewed.");
        }

        return response($response, 201);
    }

    public function getAvailableCategoriesForUser(User $user = null)
    {
        $categories = Category::with(['classifications', 'descriptors', 'types']);
        if ($user && $user->can('create designated spots')) {
            return $categories->get();
        }
        return $categories->where('crowdsource', true)->get();
    }

    public function getDefaults(Request $request)
    {
        $user = auth('api')->user();
        $categories = $this->getAvailableCategoriesForUser($user);

        if ($categoryName = $request->input('category')) {
            $category = Category::where('name', $categoryName)->first();
        } else {
            $category = $categories->first();
        }

        $descriptors = $category->descriptors;
        $classifications = $category->classifications;
        $types = $category->types;

        $requiredSpotData = [
            'lat'               => 'number',
            'lng'               => 'number',
            'notes'             => 'string',
            'descriptors'       => 'object',
            'type_id'           => 'number',
            'classification_id' => 'number',
        ];

        return [
            'requiredData'              => $requiredSpotData,
            'availableTypes'            => $types,
            'requiredDescriptors'       => $descriptors,
            'availableClassifications'  => $this->filterClassifications($classifications, $user),
            'availableCategories'       => $categories,
        ];
    }

    /**
     * Endpoint hit to approve a nap spot.
     *
     * @param Request $request the http request.
     * @param Spot    $spot    the spot to try and approve
     *
     * @return void
     */
    public function approve(Request $request, Spot $spot)
    {
        $spot->approved = true;
        if (!$spot->author->can('create designated spots')) {
            $classification = $spot->classification;
            $classification = Classification::where('category_id', $classification->category_id)->where('name', 'Public')->first();
            $spot->classification_id = $classification->id;
        }
        $spot->save();
    }
}
