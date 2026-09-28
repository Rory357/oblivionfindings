<?php

return json_decode(<<<'JSON'
{
  "sources": {
    "journeys": {
      "label": "Recorded business journeys",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "distance": {
          "label": "Recorded distance",
          "type": "number",
          "unit": "km",
          "description": "Recorded source value; missing remains unknown."
        },
        "duration": {
          "label": "Recorded duration",
          "type": "number",
          "unit": "hours",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "One business journey per row. Personal trips and consent-blocked telemetry are excluded. Missing distance is not zero.",
      "permission": null
    },
    "bookings": {
      "label": "Vehicle reservations",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "booked_hours": {
          "label": "Booked hours",
          "type": "number",
          "unit": "hours",
          "description": "Recorded source value; missing remains unknown."
        },
        "used_hours": {
          "label": "Checkout to return",
          "type": "number",
          "unit": "hours",
          "description": "Recorded source value; missing remains unknown."
        },
        "distance": {
          "label": "Odometer difference",
          "type": "number",
          "unit": "km",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "One reservation per row. Checkout duration is not GPS movement or proof of service. Availability is not inferred.",
      "permission": null
    },
    "maintenance": {
      "label": "Maintenance work",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "waiting_reason": {
          "label": "Waiting reason",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "age_days": {
          "label": "Age at period end",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "completed_at": {
          "label": "Completed at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Work orders created in the period. Open work remains distinct from completed work.",
      "permission": null
    },
    "demand": {
      "label": "Transport demand",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "seats": {
          "label": "Required seats",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "wheelchair": {
          "label": "Wheelchair required",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "escort": {
          "label": "Escort required",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "reason": {
          "label": "Recorded reason",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "One transport request per row, using the canonical request scope. No client names or care details.",
      "permission": null
    },
    "resources": {
      "label": "Resource and data quality",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "last_contact": {
          "label": "Last recorded contact",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "distance": {
          "label": "Current odometer",
          "type": "number",
          "unit": "km",
          "description": "Recorded source value; missing remains unknown."
        },
        "tracking": {
          "label": "Tracking evidence",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Current resource snapshot, captured at run time. Last contact is not proof of use or non-use.",
      "permission": null
    },
    "costs": {
      "label": "Recorded fuel costs",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "cost": {
          "label": "Recorded fuel cost",
          "type": "number",
          "unit": "NZD",
          "description": "Recorded source value; missing remains unknown."
        },
        "litres": {
          "label": "Recorded fuel volume",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "cost_stage": {
          "label": "Cost evidence",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Fuel source amounts only, under Finance site access. Not a ledger total, maintenance estimate or replacement valuation.",
      "permission": "finance.ap.view"
    },
    "client_locations": {
      "label": "Client location observations",
      "domain": "client",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "observed_at": {
          "label": "Observed at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "received_at": {
          "label": "Received at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "latitude": {
          "label": "Latitude",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "longitude": {
          "label": "Longitude",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "accuracy": {
          "label": "Accuracy",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "battery": {
          "label": "Reported battery",
          "type": "number",
          "unit": "percent",
          "description": "Recorded source value; missing remains unknown."
        },
        "speed": {
          "label": "Reported speed",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "event_type": {
          "label": "Event type",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "source": {
          "label": "Source",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "source_id": {
          "label": "Source ID",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "gap_minutes": {
          "label": "Gap from previous observation",
          "type": "number",
          "unit": "minutes",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "One source observation per row within current consent, assignment and retention. Gaps do not establish whereabouts.",
      "permission": null
    },
    "client_signals": {
      "label": "Client tracker signals",
      "domain": "client",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "observed_at": {
          "label": "Observed at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "received_at": {
          "label": "Received at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "battery": {
          "label": "Reported battery",
          "type": "number",
          "unit": "percent",
          "description": "Recorded source value; missing remains unknown."
        },
        "charging": {
          "label": "Charging evidence",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "external_power": {
          "label": "External power",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "motion": {
          "label": "Reported movement",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "event_type": {
          "label": "Event type",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Battery, power and motion describe the device. Unsupported or unreported evidence stays unknown. No health or attendance conclusions. Charging uses reported charging_status; external power is separate. Unsupported signals remain unknown.",
      "permission": null
    },
    "client_zones": {
      "label": "Agreed-zone safety events",
      "domain": "client",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "observed_at": {
          "label": "Observed at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "received_at": {
          "label": "Received at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "battery": {
          "label": "Reported battery",
          "type": "number",
          "unit": "percent",
          "description": "Recorded source value; missing remains unknown."
        },
        "charging": {
          "label": "Charging evidence",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "external_power": {
          "label": "External power",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "motion": {
          "label": "Reported movement",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "event_type": {
          "label": "Event type",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "rule_id": {
          "label": "Reported rule ID",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "geometry_version": {
          "label": "Reported geometry version",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Canonical zone-breach evidence, bound to the authorised assignment and immutable rule revision. This is not an entry/exit or dwell-time history.",
      "permission": null
    },
    "client_alerts": {
      "label": "Client safety response",
      "domain": "client",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "event_type": {
          "label": "Alert type",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "ack_minutes": {
          "label": "Acknowledgement interval",
          "type": "number",
          "unit": "minutes",
          "description": "Recorded source value; missing remains unknown."
        },
        "resolution_minutes": {
          "label": "Resolution interval",
          "type": "number",
          "unit": "minutes",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Canonical Control Room lifecycle. Unresolved alerts are excluded from completed-response averages.",
      "permission": null
    },
    "client_authority": {
      "label": "Current client tracking authority",
      "domain": "client",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "purpose": {
          "label": "Tracking purpose",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "assigned_at": {
          "label": "Assigned at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "collection_started_at": {
          "label": "Collection started",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "retention_days": {
          "label": "Retention days",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Current authorised assignment only. Historical assignments cannot reopen withdrawn consent.",
      "permission": null
    },
    "staff_sessions": {
      "label": "Lone-worker sessions",
      "domain": "staff",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "started_at": {
          "label": "Started",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "ended_at": {
          "label": "Ended",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "expected_end_at": {
          "label": "Expected end",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "interval_minutes": {
          "label": "Expected check-in interval",
          "type": "number",
          "unit": "minutes",
          "description": "Recorded source value; missing remains unknown."
        },
        "check_ins": {
          "label": "Received check-ins",
          "type": "number",
          "unit": "count",
          "description": "Recorded source value; missing remains unknown."
        },
        "duration": {
          "label": "Closed session duration",
          "type": "number",
          "unit": "hours",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Safety sessions, not worked or payable hours. Sessions need not have a GPS device.",
      "permission": null
    },
    "staff_alerts": {
      "label": "Staff safety response",
      "domain": "staff",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "event_type": {
          "label": "Alert type",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "ack_minutes": {
          "label": "Acknowledgement interval",
          "type": "number",
          "unit": "minutes",
          "description": "Recorded source value; missing remains unknown."
        },
        "resolution_minutes": {
          "label": "Resolution interval",
          "type": "number",
          "unit": "minutes",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Only canonical lone-worker alerts linked to an authorised session. Legacy alerts are not added.",
      "permission": null
    },
    "staff_locations": {
      "label": "Session location observations",
      "domain": "staff",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "observed_at": {
          "label": "Observed at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "received_at": {
          "label": "Received at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "latitude": {
          "label": "Latitude",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "longitude": {
          "label": "Longitude",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "accuracy": {
          "label": "Accuracy",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "battery": {
          "label": "Reported battery",
          "type": "number",
          "unit": "percent",
          "description": "Recorded source value; missing remains unknown."
        },
        "speed": {
          "label": "Reported speed",
          "type": "number",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "event_type": {
          "label": "Event type",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "source": {
          "label": "Source",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "source_id": {
          "label": "Source ID",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "gap_minutes": {
          "label": "Gap from previous observation",
          "type": "number",
          "unit": "minutes",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Session, assignment, collection and retention must all overlap. Historical report permission is separate from live telemetry permission.",
      "permission": "assets.telemetry.history"
    },
    "staff_readiness": {
      "label": "Session tracker readiness",
      "domain": "staff",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "observed_at": {
          "label": "Observed at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "received_at": {
          "label": "Received at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "battery": {
          "label": "Reported battery",
          "type": "number",
          "unit": "percent",
          "description": "Recorded source value; missing remains unknown."
        },
        "charging": {
          "label": "Charging evidence",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "external_power": {
          "label": "External power",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "motion": {
          "label": "Reported movement",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "event_type": {
          "label": "Event type",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Reported tracker evidence inside the selected session. No off-session collection or inferred battery life.",
      "permission": "assets.telemetry.history"
    },
    "my_safety": {
      "label": "My safety history",
      "domain": "self",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "started_at": {
          "label": "Started",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "ended_at": {
          "label": "Ended",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "check_ins": {
          "label": "Received check-ins",
          "type": "number",
          "unit": "count",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Your own safety sessions. Client identities and third-party details are omitted.",
      "permission": null
    },
    "obligations": {
      "label": "Due obligation reminders",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "due_on": {
          "label": "Due date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "due_km": {
          "label": "Due odometer",
          "type": "number",
          "unit": "km",
          "description": "Recorded source value; missing remains unknown."
        },
        "source_type": {
          "label": "Obligation source",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "source_id": {
          "label": "Source ID",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "One canonical due-point reminder per row. A reminder is not proof of compliance or completion.",
      "permission": null
    },
    "custody": {
      "label": "Asset custody movements",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "received_at": {
          "label": "Received at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "returned_at": {
          "label": "Returned at",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "kind": {
          "label": "Movement kind",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "One recorded custody movement. Both origin and destination must be authorised. No inferred receipt.",
      "permission": null
    },
    "stocktakes": {
      "label": "Stocktake coverage and exceptions",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "expected": {
          "label": "Expected items",
          "type": "number",
          "unit": "count",
          "description": "Recorded source value; missing remains unknown."
        },
        "found": {
          "label": "Found items",
          "type": "number",
          "unit": "count",
          "description": "Recorded source value; missing remains unknown."
        },
        "exceptions": {
          "label": "Recorded exceptions",
          "type": "number",
          "unit": "count",
          "description": "Recorded source value; missing remains unknown."
        },
        "pending": {
          "label": "Uncounted items",
          "type": "number",
          "unit": "count",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "One stocktake per row. All retained asset references must remain authorised. Physical observations do not rewrite assignments.",
      "permission": "assets.viewAny"
    },
    "downtime": {
      "label": "Recorded maintenance restriction intervals",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "restriction_hours": {
          "label": "Unioned restriction duration",
          "type": "number",
          "unit": "hours",
          "description": "Recorded source value; missing remains unknown."
        },
        "restriction_records": {
          "label": "Contributing restrictions",
          "type": "number",
          "unit": "count",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "One resource per selected period. Overlapping restrictions are unioned and clipped to the period. This does not infer GPS inactivity or available hours.",
      "permission": null
    },
    "finance_bills": {
      "label": "Finance supplier invoices",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "cost": {
          "label": "Invoice total incl GST",
          "type": "number",
          "unit": "NZD",
          "description": "Recorded source value; missing remains unknown."
        },
        "paid": {
          "label": "Amount paid",
          "type": "number",
          "unit": "NZD",
          "description": "Recorded source value; missing remains unknown."
        },
        "cost_stage": {
          "label": "Finance stage",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "journal_status": {
          "label": "Journal status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "Finance-owned invoices canonically assigned to a resource. Invoice totals, payment and posting are separate; orders and fuel logs are not added to this total.",
      "permission": "finance.ap.view"
    },
    "resource_costs": {
      "label": "Resource cost and distance",
      "domain": "fleet",
      "fields": {
        "reference": {
          "label": "Reference",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "date": {
          "label": "Date",
          "type": "date",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "resource": {
          "label": "Resource",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "site": {
          "label": "Site",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "status": {
          "label": "Status",
          "type": "text",
          "unit": "number",
          "description": "Recorded source value; missing remains unknown."
        },
        "posted_cost": {
          "label": "Posted invoice total incl GST",
          "type": "number",
          "unit": "NZD",
          "description": "Recorded source value; missing remains unknown."
        },
        "distance": {
          "label": "Known business distance",
          "type": "number",
          "unit": "km",
          "description": "Sum of positive recorded business-trip distance; zero and negative records are excluded from the denominator."
        },
        "missing_distance": {
          "label": "Journeys without positive distance",
          "type": "number",
          "unit": "count",
          "description": "Null, zero and negative stored distances cannot support a cost denominator."
        },
        "cost_per_km": {
          "label": "Posted invoice cost per known km",
          "type": "number",
          "unit": "NZD",
          "description": "Recorded source value; missing remains unknown."
        },
        "fuel_cost": {
          "label": "Recorded fuel cost (separate)",
          "type": "number",
          "unit": "NZD",
          "description": "Recorded source value; missing remains unknown."
        }
      },
      "note": "One vehicle per period. Posted non-reversed, resource-bound invoices divided by known business-trip distance. Missing/zero distance yields unknown. Fuel is a separate evidence stage, not added to invoices.",
      "permission": "finance.ap.view"
    }
  },
  "templates": [
    {
      "id": "booking-use",
      "name": "Booking versus recorded use",
      "source": "bookings"
    },
    {
      "id": "maintenance",
      "name": "Maintenance and waiting work",
      "source": "maintenance"
    },
    {
      "id": "costs",
      "name": "Costs per resource and kilometre",
      "source": "resource_costs"
    },
    {
      "id": "demand",
      "name": "Demand and suitability",
      "source": "demand"
    },
    {
      "id": "quality",
      "name": "Resource data quality",
      "source": "resources"
    },
    {
      "id": "client-location",
      "name": "Location observations",
      "source": "client_locations"
    },
    {
      "id": "client-battery",
      "name": "Battery and charging",
      "source": "client_signals"
    },
    {
      "id": "client-movement",
      "name": "Reported movement",
      "source": "client_signals"
    },
    {
      "id": "client-zones",
      "name": "Agreed-zone events",
      "source": "client_zones"
    },
    {
      "id": "client-safety",
      "name": "Client safety response",
      "source": "client_alerts"
    },
    {
      "id": "client-authority",
      "name": "Tracking authority",
      "source": "client_authority"
    },
    {
      "id": "staff-sessions",
      "name": "Lone-worker sessions",
      "source": "staff_sessions"
    },
    {
      "id": "staff-response",
      "name": "Staff safety response",
      "source": "staff_alerts"
    },
    {
      "id": "staff-location",
      "name": "Session location evidence",
      "source": "staff_locations"
    },
    {
      "id": "staff-readiness",
      "name": "Tracker readiness",
      "source": "staff_readiness"
    },
    {
      "id": "my-history",
      "name": "My safety history",
      "source": "my_safety"
    },
    {
      "id": "obligations",
      "name": "Due obligations",
      "source": "obligations"
    },
    {
      "id": "custody",
      "name": "Borrowing and custody",
      "source": "custody"
    },
    {
      "id": "stocktake",
      "name": "Stocktake exceptions",
      "source": "stocktakes"
    },
    {
      "id": "downtime",
      "name": "Maintenance restriction duration",
      "source": "downtime"
    },
    {
      "id": "finance",
      "name": "Finance invoices and payment stages",
      "source": "finance_bills"
    }
  ]
}
JSON, true, 512, JSON_THROW_ON_ERROR);
