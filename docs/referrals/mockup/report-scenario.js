import { seed } from './data';
import { clone, normalise, today } from './model';

// Explicitly selected fictional fixtures; never substituted for measured chart data.
export function reportingScenario() {
    const result = normalise(seed(), seed());
    result.scenario = 'reporting';
    const day = (offset) => {
        const d = new Date(today() + 'T12:00:00Z');
        d.setUTCDate(d.getUTCDate() + offset);
        return d.toISOString().slice(0, 10);
    };
    const timestamp = (offset) => day(offset) + 'T00:00:00.000Z';
    const template = result.records.find((r) => r.name === 'Noah Clarke');
    for (let i = 0; i < 24; i++) {
        const received = -(3 + ((i * 7) % 42));
        const converted = i % 6 === 0;
        const closed = i % 6 === 1;
        const r = clone(template);
        Object.assign(r, {
            id: `DEMO-${2000 + i}`,
            name: `Fictional person ${i + 1}`,
            preferred: `Example ${i + 1}`,
            email: `example-${i + 1}@example.invalid`,
            phone: '021 000 0000',
            createdAt: timestamp(received),
            stageAt: timestamp(received + 2),
            source: ['Website', 'Phone', 'Professional'][i % 3],
            stage: converted
                ? 'Converted'
                : closed
                  ? 'Closed'
                  : ['Assessment', 'Funding assistance', 'On hold', 'Triage'][
                        i % 4
                    ],
            owner: i % 5 === 0 ? 'Intake team' : 'Emma Taylor',
            site: i % 5 === 0 ? 'Central intake' : 'Auckland',
            personId: converted ? `DEMO-SP-${i}` : null,
            serviceStarted: converted && i % 12 === 0,
            holdReason: i % 4 === 2 ? 'Capacity' : null,
            nextDone: converted || closed,
            due: day((i % 3) - 1),
            next: 'Review the fictional support plan',
            notes:
                i % 4 === 0
                    ? []
                    : [
                          {
                              id: `DEMO-N-${i}`,
                              at: timestamp(received + 1),
                              occurredAt: timestamp(received + 1),
                              successful: true,
                              outcome: 'Spoke with person',
                              text: 'Fictional successful contact for reporting review.',
                              by: 'Emma Taylor',
                          },
                      ],
            closureHistory: closed
                ? [
                      {
                          at: timestamp(received + 2),
                          from: 'Assessment',
                          to: 'Closed',
                          category: 'Another provider',
                          reason: 'Fictional example chose another provider',
                          by: 'Emma Taylor',
                      },
                  ]
                : [],
            tasks: [],
            documents: [],
            contacts: [],
            referrerRecord: null,
            activity: [
                {
                    at: timestamp(received),
                    by: 'Intake team',
                    text: 'Fictional reporting scenario received',
                },
            ],
        });
        const f = r.funding[0];
        Object.assign(f, {
            id: `DEMO-F-${i}`,
            reference: `FICTIONAL-${i}`,
            createdAt: timestamp(received),
            validFrom: day(received),
            validTo: day(120),
            nextDone: converted || closed,
        });
        if (!converted && i % 3 === 0)
            Object.assign(f, {
                verified: false,
                status: 'Preparing',
                amount: null,
                awarded: null,
                allocated: null,
            });
        else if (!converted && i % 3 === 1)
            Object.assign(f, {
                decision: 'Partial approval',
                amount: 800,
                awarded: 800,
                allocated: 800,
            });
        if (converted) {
            Object.assign(r.proposal, {
                approved: true,
                accepted: true,
                approvedBy: 'Sam Patel',
                approvedAt: timestamp(received + 1),
                allocations: [
                    { lineId: 'support', fundingId: f.id, weekly: 1200 },
                ],
            });
            result.people.push({
                id: r.personId,
                name: r.name,
                site: r.site,
                status: r.serviceStarted ? 'Active' : 'Onboarding',
                services: ['Supported living'],
                referralId: r.id,
            });
        }
        result.records.push(r);
    }
    return result;
}
