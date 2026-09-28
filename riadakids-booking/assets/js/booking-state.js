/**
 * RiadaKids Booking Wizard — Module 1: State
 */
window.RKBookingState = {
    program_id: 0,
    program_name: '',

    adventure_id: 0,
    adventure_name: '',

    session_id: 0,
    session_name: '',
    session_description: '',

    ssa_event_id: 0,

    child_ids: [],
    children_names: [],

    event_id: 0,
    event_name: '',

    appointment_id: 0,
    appointment_datetime: '',

    booking_id: 0,
    booking_uuid: '',

    customer_timezone: '',

    credits_used: 0,

    currentStep: 1
};

// Initialisation de l'espace de nommage global pour interconnecter les fichiers
window.RiadaKidsWizard = window.RiadaKidsWizard || {};